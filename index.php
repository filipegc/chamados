<?php
// Adiciona o guardião no topo da página
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// --- CONFIGURAÇÕES DE PAGINAÇÃO ---
define('RESULTS_PER_PAGE', 15);

// --- LÓGICA DO FILTRO E PAGINAÇÃO ---
$stmt_interval = $conn->prepare("SELECT config_value FROM sistema_config WHERE config_key = 'auto_fetch_interval_minutes'");
$stmt_interval->execute();
$result_interval = $stmt_interval->get_result()->fetch_assoc();
$auto_fetch_interval = (int)($result_interval['config_value'] ?? 10); // Padrão de 10 minutos
$stmt_interval->close();
$view_mode = $_GET['view'] ?? 'all';
$current_user_id = $_SESSION['usuario_id'];
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$is_searching = !empty($search_query);
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * RESULTS_PER_PAGE;


// ADICIONE ESTAS LINHAS
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Atualiza a flag is_searching para incluir as datas
$is_searching = !empty($search_query) || !empty($start_date) || !empty($end_date);

$selected_category_id = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
if (isset($_GET['category_id']) && $_GET['category_id'] === '') {
    $selected_category_id = null;
}

$stmt_categories = $conn->prepare("SELECT id, nome FROM categorias_chamado WHERE ativa = 1 ORDER BY nome ASC");
if (!$stmt_categories) {
    error_log("Erro ao preparar query de categorias: " . $conn->error);
    $all_categories = [];
} else {
    $stmt_categories->execute();
    $all_categories = $stmt_categories->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_categories->close();
}


// --- FUNÇÕES AUXILIARES ---

function format_time_diff($start, $end) {
    if (!$start || !$end) return '-';
    $interval = (new DateTime($start))->diff(new DateTime($end));
    $parts = [];
    if ($interval->d > 0) $parts[] = $interval->d . 'd';
    if ($interval->h > 0) $parts[] = $interval->h . 'h';
    if ($interval->i > 0) $parts[] = $interval->i . 'm';
    if (empty($parts) && $interval->s >= 0) $parts[] = $interval->s . 's';
    return empty($parts) ? '0m' : implode(' ', $parts);
}

function render_ticket_table($result) {
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $requer_atencao = (bool)($row['requer_atencao'] ?? false);
            $row_class = $requer_atencao ? 'table-info' : '';
            echo "<tr data-ticket-id='" . $row['id'] . "' onclick=\"window.location='view_ticket.php?id=" . $row['id'] . "';\" class='" . $row_class . "'>";
            echo "<td>#" . $row['id'] . ($requer_atencao ? " <span class='badge bg-primary'>Novo</span>" : "") . "</td>";
            echo "<td><span class='badge rounded-pill status-" . strtolower($row['status']) . "'>" . $row['status'] . "</span></td>";
            echo "<td><span class='badge prioridade-" . strtolower($row['prioridade']) . "'>" . $row['prioridade'] . "</span></td>";
            echo "<td>" . htmlspecialchars($row['nome_usuario'] ?? 'N/A') . "</td>";
            echo "<td class='text-center'><span class='badge bg-secondary'>" . ($row['message_count'] ?? 0) . "</span></td>";
            echo "<td>" . htmlspecialchars($row['assunto']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email_cliente']) . "</td>";
            echo "<td>" . htmlspecialchars($row['nome_categoria'] ?? 'N/A') . "</td>";
            $arrival_date = $row['arrival_date'] ?? $row['data_criacao'];
            echo "<td>" . date('d/m/Y H:i', strtotime($arrival_date)) . "</td>";
            echo "<td class='text-center'>" . format_time_diff($arrival_date, $row['first_reply_date'] ?? null) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr class='no-tickets-row'><td colspan='10' class='text-center text-muted'>Nenhum chamado encontrado.</td></tr>";
    }
}

function render_pagination($current_page, $total_pages, $base_params) {
    if ($total_pages <= 1) return;
    echo '<nav aria-label="Paginação de chamados"><ul class="pagination justify-content-center mt-4">';
    for ($i = 1; $i <= $total_pages; $i++) {
        $page_params = array_merge($base_params, ['page' => $i]);
        $link = 'index.php?' . http_build_query($page_params);
        $active_class = ($i == $current_page) ? 'active' : '';
        echo "<li class='page-item {$active_class}'><a class='page-link' href='{$link}'>{$i}</a></li>";
    }
    echo '</ul></nav>';
}

function get_filtered_query_parts($view_mode, $current_user_id, $status = null, $search_query = '', $category_id = null, $start_date = '', $end_date = '') {
    $sql_where = '';
    $params = [];
    $types = '';
    $conditions = [];

    if ($status) {
        $conditions[] = "c.status = ?";
        $types .= 's';
        $params[] = $status;
    }
    if ($view_mode === 'mine') {
        $conditions[] = "c.usuario_id = ?";
        $types .= 'i';
        $params[] = $current_user_id;
    }
    if ($category_id !== null) {
        $conditions[] = "c.categoria_id = ?";
        $types .= 'i';
        $params[] = $category_id;
    }
    if (!empty($search_query)) {
        $like_plain = '%' . $search_query . '%';
        $conditions[] = "(c.assunto LIKE ? OR c.email_cliente LIKE ? OR c.id LIKE ?)";
        $types .= 'sss';
        array_push($params, $like_plain, $like_plain, $like_plain);
    }
    // LÓGICA DE DATAS ADICIONADA
    if (!empty($start_date)) {
        $conditions[] = "c.data_criacao >= ?";
        $types .= 's';
        $params[] = $start_date . ' 00:00:00';
    }
    if (!empty($end_date)) {
        $conditions[] = "c.data_criacao <= ?";
        $types .= 's';
        $params[] = $end_date . ' 23:59:59';
    }
    
    if (!empty($conditions)) {
        $sql_where = " WHERE " . implode(' AND ', $conditions);
    }
    return ['where' => $sql_where, 'params' => $params, 'types' => $types];
}

function get_base_query() {
    return "
        SELECT 
            c.*, 
            u.nome as nome_usuario,
            cat.nome as nome_categoria, 
            (SELECT COUNT(*) FROM mensagens m_count WHERE m_count.chamado_id = c.id) as message_count,
            (SELECT MIN(m_first.data_envio) FROM mensagens m_first WHERE m_first.chamado_id = c.id) as arrival_date,
            (SELECT MIN(m_resp.data_envio) FROM mensagens m_resp WHERE m_resp.chamado_id = c.id AND m_resp.tipo = 'enviado') as first_reply_date
        FROM chamados c
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        LEFT JOIN categorias_chamado cat ON c.categoria_id = cat.id
    ";
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .container { background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-top: 40px; margin-bottom: 40px; }
        .status-aberto { background-color: #e6ffed; color: #008000; border: 1px solid #c2f9d5; }
        .status-pendente { background-color: #fff9e6; color: #b8860b; border: 1px solid #ffedb3; }
        .status-fechado { background-color: #ffe6e6; color: #b22222; border: 1px solid #ffc2c2; }
        .prioridade-baixa { --bs-badge-bg: #8c92a3; border: 1px solid #afb5c5; }
        .prioridade-media { --bs-badge-bg: #ffb400; color: #4d3600; border: 1px solid #ffdb99; }
        .prioridade-alta { --bs-badge-bg: #e03131; border: 1px solid #ff7070; }
        .table-hover tbody tr { cursor: pointer; }
        .table-hover tbody tr:hover { background-color: #f5f7f9; }
        .table-hover tbody tr.table-info:hover { background-color: #c0effc; }
        .nav-tabs .nav-link { color: #555; border: none; border-bottom: 3px solid transparent; transition: all 0.2s ease-in-out; padding: 10px 20px; }
        .nav-tabs .nav-link.active { color: #0d6efd; background-color: transparent; border-bottom: 3px solid #0d6efd; font-weight: bold; }
        .nav-tabs .nav-link:hover:not(.active) { color: #0a58ca; border-bottom: 3px solid #dee2e6; }
        .btn-group-filter .btn { border-radius: 5px; margin: 0 2px; }
        .table { table-layout: fixed; width: 100%; }
        .table th, .table td { word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <h1>Gerenciador de Chamados</h1>
            <div class="d-flex align-items-center">
                <span class="me-3 text-muted">Olá, <strong><?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></strong></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Sair</a>
            </div>
        </div>

        <div class="card mb-4 shadow-sm">
            <div class="card-body">
                <div class="row align-items-center mb-3 g-3">
                    <div class="col-md-7 col-lg-8">
                        <form action="index.php" method="GET" class="row g-3 align-items-center">
    <div class="col-md-5">
        <input type="text" class="form-control rounded-pill" name="q" placeholder="Buscar por assunto, e-mail..." value="<?php echo htmlspecialchars($search_query); ?>">
    </div>
    <div class="col-md-3">
        <input type="date" class="form-control" name="start_date" title="Data Inicial" value="<?php echo htmlspecialchars($start_date); ?>">
    </div>
    <div class="col-md-3">
        <input type="date" class="form-control" name="end_date" title="Data Final" value="<?php echo htmlspecialchars($end_date); ?>">
    </div>
    <div class="col-md-1">
        <button type="submit" class="btn btn-success rounded-pill w-100"><i class="bi bi-search"></i></button>
    </div>

    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
    <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($selected_category_id ?? ''); ?>">

    <?php if ($is_searching): ?>
        <div class="col-12 text-center mt-2">
             <a href="index.php?view=<?php echo htmlspecialchars($view_mode); ?>" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-x-lg"></i> Limpar todos os filtros</a>
        </div>
    <?php endif; ?>
</form>
                    </div>
                    <div class="col-md-5 col-lg-4 text-end d-flex justify-content-end align-items-center">
                        <div class="btn-group" role="group">
                            <a href="compose.php" class="btn btn-success rounded-pill"><i class="bi bi-plus-circle"></i> Novo Chamado</a>
                            <button type="button" class="btn btn-info dropdown-toggle rounded-pill ms-2" id="email-action-btn" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-envelope"></i> E-mails
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item" href="scripts/fetch_emails.php?mode=unseen" target="_blank"><i class="bi bi-envelope-fill me-2"></i> Verificar Novos</a></li>
                                <li><a class="dropdown-item" href="scripts/fetch_emails.php?mode=all" target="_blank"><i class="bi bi-arrow-down-square-fill me-2"></i> Importar Antigos</a></li>
                            </ul>
                        </div>
                        <span id="fetch-timer" class="badge bg-light text-dark ms-2 p-2 border"></span>
                     <button id="pause-resume-btn" class="btn btn-sm btn-outline-secondary ms-1" title="Pausar/Retomar Automação">
                <i class="bi bi-pause-fill"></i>
            </button>
                    </div>
                </div>
                <hr class="my-3">
                <div class="row align-items-center g-3">
                    <div class="col-md-6">
                        <div class="d-flex flex-wrap gap-2">
                            <div class="btn-group btn-group-filter">
                                <a href="index.php?view=all" class="btn <?php echo ($view_mode === 'all') ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="bi bi-globe"></i> Todos os Chamados</a>
                                <a href="index.php?view=mine" class="btn <?php echo ($view_mode === 'mine') ? 'btn-primary' : 'btn-outline-primary'; ?>"><i class="bi bi-person-fill"></i> Meus Chamados</a>
                            </div>
                            <form action="index.php" method="GET" class="d-flex align-items-center flex-grow-1" style="max-width: 300px;">
                                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>">
                                <label for="category_filter" class="form-label mb-0 me-2 text-muted">Categoria:</label>
                                <select name="category_id" id="category_filter" class="form-select" onchange="this.form.submit()">
                                    <option value="">Todas</option>
                                    <?php foreach ($all_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo ($selected_category_id == $category['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <?php if (isset($_SESSION['usuario_role']) && $_SESSION['usuario_role'] === 'admin'): ?>
                        <div class="btn-group flex-wrap gap-2">
                            <a href="manage_categories.php" class="btn btn-info rounded-pill"><i class="bi bi-tags"></i> Categorias</a>
                            <a href="manage_users.php" class="btn btn-danger rounded-pill"><i class="bi bi-people"></i> Usuários</a>
                            <a href="reports_dashboard.php" class="btn btn-success rounded-pill"><i class="bi bi-graph-up"></i> Relatórios</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_searching): ?>
            <div class="card shadow-sm">
                <div class="card-header fs-5">Resultados da busca por: <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong></div>
                <div class="card-body">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>ID</th><th>Status</th><th>Prioridade</th><th>Atribuído a</th><th class="text-center">Msgs</th><th>Assunto</th><th>Cliente</th><th>Categoria</th><th>Chegada</th><th class="text-center">1ª Resp.</th></tr></thead>
                        <tbody>
                            <?php
                            $query_parts = get_filtered_query_parts($view_mode, $current_user_id, null, $search_query, $selected_category_id, $start_date, $end_date);
                            $count_sql = "SELECT COUNT(DISTINCT c.id) as total FROM chamados c LEFT JOIN mensagens m ON c.id = m.chamado_id " . $query_parts['where'];
                            $stmt_count = $conn->prepare($count_sql);
                            if (!empty($query_parts['types'])) $stmt_count->bind_param($query_parts['types'], ...$query_parts['params']);
                            $stmt_count->execute();
                            $total_results = $stmt_count->get_result()->fetch_assoc()['total'];
                            $total_pages = ceil($total_results / RESULTS_PER_PAGE);

                            $data_sql = get_base_query() . " LEFT JOIN mensagens m ON c.id = m.chamado_id " . $query_parts['where'] . " GROUP BY c.id ORDER BY c.ultimo_update DESC LIMIT ? OFFSET ?";
                            $query_parts['types'] .= 'ii';
                            $query_parts['params'][] = RESULTS_PER_PAGE;
                            $query_parts['params'][] = $offset;
                            $stmt_data = $conn->prepare($data_sql);
                            $stmt_data->bind_param($query_parts['types'], ...$query_parts['params']);
                            $stmt_data->execute();
                            render_ticket_table($stmt_data->get_result());
                            ?>
                        </tbody>
                    </table>
                    <?php render_pagination($page, $total_pages, ['view' => $view_mode, 'q' => $search_query, 'category_id' => $selected_category_id]); ?>
                </div>
            </div>
        <?php else: ?>
            <ul class="nav nav-tabs" id="statusTabs" role="tablist">
                <?php 
                $active_tab_from_get = $_GET['tab'] ?? 'aberto';
                foreach (['Aberto', 'Pendente', 'Fechado'] as $status):
                    $is_active_tab = (strtolower($status) === $active_tab_from_get);
                    $query_parts_count = get_filtered_query_parts($view_mode, $current_user_id, $status, '', $selected_category_id, $start_date, $end_date);
                    $count_sql = "SELECT COUNT(DISTINCT c.id) as total FROM chamados c " . $query_parts_count['where'];
                    $stmt_count = $conn->prepare($count_sql);
                    if (!empty($query_parts_count['types'])) $stmt_count->bind_param($query_parts_count['types'], ...$query_parts_count['params']);
                    $stmt_count->execute();
                    $count = $stmt_count->get_result()->fetch_assoc()['total'];
                ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $is_active_tab ? 'active' : ''; ?>" id="<?php echo strtolower($status); ?>-tab" data-bs-toggle="tab" data-bs-target="#<?php echo strtolower($status); ?>-pane" type="button">
                        <?php echo $status; ?> <span class="badge bg-secondary ms-1"><?php echo $count; ?></span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-content" id="statusTabsContent">
                <?php foreach (['Aberto', 'Pendente', 'Fechado'] as $status): 
                    $is_active_tab = (strtolower($status) === $active_tab_from_get);
                ?>
                <div class="tab-pane fade <?php echo $is_active_tab ? 'show active' : ''; ?>" id="<?php echo strtolower($status); ?>-pane" role="tabpanel">
                    <div class="card border-top-0 rounded-0 rounded-bottom shadow-sm">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead><tr><th>ID</th><th>Status</th><th>Prioridade</th><th>Atribuído a</th><th class="text-center">Msgs</th><th>Assunto</th><th>Cliente</th><th>Categoria</th><th>Chegada</th><th class="text-center">1ª Resp.</th></tr></thead>
                                    <tbody>
                                        <?php
                                        // CORRIGIDO: A condição "if ($is_active_tab)" foi removida daqui.
                                        // Agora, o PHP vai montar a tabela para TODAS as abas.
                                        
                                           $query_parts = get_filtered_query_parts($view_mode, $current_user_id, $status, '', $selected_category_id, $start_date, $end_date);
                                        
                                        // A contagem já foi feita para as abas, podemos reutilizar o resultado se quisermos
                                        $count_sql_pane = "SELECT COUNT(DISTINCT c.id) as total FROM chamados c " . $query_parts['where'];
                                        $stmt_count_pane = $conn->prepare($count_sql_pane);
                                        if (!empty($query_parts['types'])) $stmt_count_pane->bind_param($query_parts['types'], ...$query_parts['params']);
                                        $stmt_count_pane->execute();
                                        $total_results = $stmt_count_pane->get_result()->fetch_assoc()['total'];
                                            $total_pages = ceil($total_results / RESULTS_PER_PAGE);

                                            $data_sql = get_base_query() . $query_parts['where'] . " GROUP BY c.id ORDER BY c.requer_atencao DESC, c.ultimo_update DESC LIMIT ? OFFSET ?";
                                            $data_types = $query_parts['types'] . 'ii';
                                            $data_params = array_merge($query_parts['params'], [RESULTS_PER_PAGE, $offset]);
                                        
                                            $stmt_data = $conn->prepare($data_sql);
                                            $stmt_data->bind_param($data_types, ...$data_params);
                                            $stmt_data->execute();
                                        
                                            render_ticket_table($stmt_data->get_result());
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php render_pagination($page, $total_pages, ['view' => $view_mode, 'tab' => strtolower($status), 'q' => '', 'category_id' => $selected_category_id]); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Função para escapar HTML e evitar ataques (Cross-Site Scripting)
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Função dedicada para processar novos chamados e atualizar a tabela
    function processNewTickets(newTickets) {
        if (newTickets && newTickets.length > 0) {
            console.log('Recebidos ' + newTickets.length + ' novos chamados. Atualizando a interface...');
            
            newTickets.reverse().forEach(ticket => {
                const existingRow = document.querySelector(`tr[data-ticket-id='${ticket.id}']`);

                if (existingRow) {
                    existingRow.classList.add('table-info');
                    return;
                }
                
                const ticketStatusPaneId = `${ticket.status.toLowerCase()}-pane`;
                const targetPane = document.getElementById(ticketStatusPaneId);

                if (targetPane) {
                    const targetTbody = targetPane.querySelector('tbody');
                    const noTicketsRow = targetTbody.querySelector('.no-tickets-row');
                    if (noTicketsRow) noTicketsRow.remove();
                    
                    const newRow = document.createElement('tr');
                    newRow.dataset.ticketId = ticket.id;
                    newRow.className = 'table-info';
                    newRow.style.cursor = 'pointer';
                    
                    newRow.innerHTML = `
                        <td>#${ticket.id} <span class="badge bg-primary">Novo</span></td>
                        <td><span class="badge rounded-pill status-${ticket.status.toLowerCase()}">${escapeHtml(ticket.status)}</span></td>
                        <td><span class="badge prioridade-${ticket.prioridade.toLowerCase()}">${escapeHtml(ticket.prioridade)}</span></td>
                        <td>${escapeHtml(ticket.nome_usuario || 'N/A')}</td>
                        <td class="text-center"><span class="badge bg-secondary">${ticket.message_count}</span></td>
                        <td>${escapeHtml(ticket.assunto)}</td>
                        <td>${escapeHtml(ticket.email_cliente)}</td>
                        <td>${escapeHtml(ticket.nome_categoria || 'N/A')}</td>
                        <td>${new Date(ticket.data_criacao).toLocaleString('pt-BR')}</td>
                        <td class="text-center">${escapeHtml(ticket.first_reply_diff || '-')}</td>`;
                    
                    targetTbody.prepend(newRow);

                    const tabButton = document.getElementById(`${ticket.status.toLowerCase()}-tab`);
                    if (tabButton) {
                        const badge = tabButton.querySelector('.badge');
                        if (badge) badge.textContent = parseInt(badge.textContent) + 1;
                    }
                }
            });
        }
    }

    // Lógica para cliques na tabela (centralizada e eficiente)
    const statusTabsContent = document.getElementById('statusTabsContent');
    if (statusTabsContent) {
        statusTabsContent.addEventListener('click', function(event) {
            const clickedRow = event.target.closest('tr');
            if (clickedRow && clickedRow.dataset.ticketId) {
                const ticketId = clickedRow.dataset.ticketId;
                if (clickedRow.classList.contains('table-info')) {
                    clickedRow.classList.remove('table-info');
                }
                window.location.href = 'view_ticket.php?id=' + ticketId;
            }
        });
    }


// Função dedicada para processar ATUALIZAÇÕES em chamados existentes
    function processTicketUpdates(updatedTickets) {
        if (updatedTickets && updatedTickets.length > 0) {
            console.log('Recebidas ' + updatedTickets.length + ' atualizações de chamados.');

            updatedTickets.forEach(ticket => {
                const existingRow = document.querySelector(`tr[data-ticket-id='${ticket.id}']`);

                // Se a linha do chamado estiver visível na tela
                if (existingRow) {
                    console.log(`Atualizando a linha para o chamado #${ticket.id}`);

                    // 1. Adiciona um destaque visual
                    existingRow.classList.add('table-info');
                    
                    // 2. Adiciona um badge de "Nova Resposta" se ainda não existir
                    const idCell = existingRow.querySelector('td:first-child');
                    if (idCell && !idCell.querySelector('.badge-resposta')) {
                         idCell.innerHTML += ` <span class='badge bg-info badge-resposta'>Nova Resposta</span>`;
                    }

                    // 3. Atualiza a contagem de mensagens
                    const msgCell = existingRow.querySelector('td:nth-child(5) .badge');
                    if(msgCell) msgCell.textContent = ticket.message_count;

                    // 4. Move a linha para o topo da sua tabela para dar visibilidade
                    const parentTbody = existingRow.parentNode;
                    parentTbody.prepend(existingRow);
                }
            });
        }
    }

    // --- LÓGICA DE VERIFICAÇÃO DE ATUALIZAÇÕES ---
    function triggerUpdateCheck() {
        // Pega a visão atual (Todos ou Meus) para passar ao script PHP
        const currentView = new URLSearchParams(window.location.search).get('view') || 'all';
        
        fetch(`get_updates_ajax.php?view=${currentView}`)
            .then(response => response.json())
            .then(data => {
                if (data.updated_tickets) {
                    processTicketUpdates(data.updated_tickets);
                }
            })
            .catch(error => console.error("Erro na busca por atualizações de chamados:", error));
    }

    // Inicia um novo laço de verificação independente, a cada 20 segundos
    setInterval(triggerUpdateCheck, 20000);

    // --- LÓGICA DO RELÓGIO E VERIFICAÇÃO DE E-MAILS ---
    const fetchButton = document.getElementById('email-action-btn');
    const timerDisplay = document.getElementById('fetch-timer');
    const pauseResumeBtn = document.getElementById('pause-resume-btn');
    
    const fetchIntervalMinutes = <?php echo $auto_fetch_interval; ?>;
    const fetchIntervalMilliseconds = fetchIntervalMinutes * 60 * 1000;
    
    let isFetchingEmails = false;
    let countdown = fetchIntervalMinutes * 60;
    let isPaused = false;
    let fetchIntervalId;
    let timerIntervalId;

    // Função que busca novos e-mails e chama a função de processamento
    function triggerEmailFetch() {
        if (isFetchingEmails || isPaused) return;
        isFetchingEmails = true;
        if (fetchButton) {
            fetchButton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verificando...';
            fetchButton.classList.add('disabled');
        }

        fetch('scripts/fetch_emails.php?source=ajax')
            .then(response => response.json())
            .then(data => {
                processNewTickets(data.new_tickets); // A mágica acontece aqui!
            })
            .catch(error => console.error("Erro na busca de e-mails:", error))
            .finally(() => {
                isFetchingEmails = false;
                if (fetchButton) {
                    fetchButton.innerHTML = '<i class="bi bi-envelope"></i> E-mails';
                    fetchButton.classList.remove('disabled');
                }
            });
    }

    // --- Funções do Timer e Controles ---
    function updateTimer() {
        if (isPaused) { timerDisplay.innerHTML = `<i class="bi bi-pause-circle"></i> Pausado`; return; }
        if (isFetchingEmails) { timerDisplay.innerHTML = `<i class="bi bi-arrow-repeat"></i> Em execução...`; return; }
        countdown--;
        if (countdown < 0) {
            triggerEmailFetch();
            countdown = fetchIntervalMinutes * 60; 
        }
        const minutes = Math.floor(countdown / 60);
        const seconds = countdown % 60;
        const displayTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        if (timerDisplay) {
            timerDisplay.innerHTML = `<i class="bi bi-clock"></i> Próxima em ${displayTime}`;
        }
    }

    function startTimers() {
        isPaused = false;
        pauseResumeBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
        setTimeout(triggerEmailFetch, 5000); // Primeira verificação após 5s
        fetchIntervalId = setInterval(triggerEmailFetch, fetchIntervalMilliseconds);
        timerIntervalId = setInterval(updateTimer, 1000);
        updateTimer();
    }

    function stopTimers() {
        isPaused = true;
        pauseResumeBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
        clearInterval(fetchIntervalId);
        clearInterval(timerIntervalId);
        updateTimer();
    }

    pauseResumeBtn.addEventListener('click', function() {
        if (isPaused) { startTimers(); } else { stopTimers(); }
    });

    // Inicia a automação
    startTimers();
});
</script>
</body>
</html>