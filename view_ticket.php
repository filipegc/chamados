<?php
// Ativa a exibição de erros para depuração. REMOVER EM PRODUÇÃO.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// 1. Validação e Obtenção do ID do Chamado
$chamado_id = (int)($_GET['id'] ?? 0); // Garante que $chamado_id é um inteiro, 0 se não presente

if ($chamado_id === 0) {
    header('Location: index.php?error=ID do chamado inválido ou ausente.');
    exit;
}

// 2. Marca o chamado como "visto"
$update_stmt = $conn->prepare("UPDATE chamados SET requer_atencao = 0 WHERE id = ?");
if (!$update_stmt) {
    // Erro de preparação, logar e exibir erro amigável
    error_log("Erro ao preparar update_stmt: " . $conn->error);
    die("Erro interno do servidor ao atualizar o chamado.");
}
$update_stmt->bind_param("i", $chamado_id);
if (!$update_stmt->execute()) {
    // Erro de execução, logar e exibir erro amigável
    error_log("Erro ao executar update_stmt: " . $update_stmt->error);
    die("Erro interno do servidor ao atualizar o chamado.");
}
$update_stmt->close();


// 3. Busca dados do chamado principal
$stmt = $conn->prepare("
    SELECT 
        c.*, 
        u.nome as nome_responsavel,
        cat.nome as nome_categoria,
        (SELECT m.remetente_nome FROM mensagens m WHERE m.chamado_id = c.id AND (m.tipo = 'enviado' OR m.tipo = 'interno') ORDER BY m.data_envio DESC LIMIT 1) as nome_ultimo_atualizador_msg
    FROM chamados c 
    LEFT JOIN usuarios u ON c.usuario_id = u.id 
    LEFT JOIN categorias_chamado cat ON c.categoria_id = cat.id 
    WHERE c.id = ?
");
if (!$stmt) {
    error_log("Erro ao preparar query de detalhes do chamado: " . $conn->error);
    die("Erro interno do servidor ao buscar detalhes do chamado.");
}
$stmt->bind_param("i", $chamado_id);
if (!$stmt->execute()) {
    error_log("Erro ao executar query de detalhes do chamado: " . $stmt->error);
    die("Erro interno do servidor ao buscar detalhes do chamado.");
}
$chamado = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$chamado) {
    // Chamado não encontrado, redireciona de volta com erro
    header('Location: index.php?error=Chamado com ID ' . $chamado_id . ' não encontrado.');
    exit;
}

// NOVO: Busca a assinatura do usuário logado para pré-preencher o editor
$user_signature_html = null;
if (isset($_SESSION['usuario_id'])) {
    $stmt_signature = $conn->prepare("SELECT signature_html FROM usuarios WHERE id = ?");
    if ($stmt_signature) {
        $stmt_signature->bind_param("i", $_SESSION['usuario_id']);
        $stmt_signature->execute();
        $result_signature = $stmt_signature->get_result();
        if ($row_signature = $result_signature->fetch_assoc()) {
            $user_signature_html = $row_signature['signature_html'];
        }
        $stmt_signature->close();
    } else {
        error_log("Erro ao preparar query de assinatura do usuário: " . $conn->error);
    }
}


// 4. Busca mensagens do chamado
$stmt = $conn->prepare("SELECT id, remetente_nome, remetente_email, destinatario_email, cc_emails, bcc_emails, corpo_html, corpo_texto, data_envio, tipo FROM mensagens WHERE chamado_id = ? ORDER BY data_envio ASC");
if (!$stmt) {
    error_log("Erro ao preparar query de mensagens: " . $conn->error);
    die("Erro interno do servidor ao buscar mensagens.");
}
$stmt->bind_param("i", $chamado_id);
if (!$stmt->execute()) {
    error_log("Erro ao executar query de mensagens: " . $stmt->error);
    die("Erro interno do servidor ao buscar mensagens.");
}
$mensagens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5. Busca todos os participantes para o resumo
$all_participants = [];
foreach ($mensagens as $msg) {
    if ($msg['tipo'] !== 'interno') { // Ignorar mensagens internas na lista de participantes de email
        if (!empty($msg['remetente_email'])) {
            $all_participants[] = $msg['remetente_email'];
        }
        if (!empty($msg['destinatario_email'])) {
            $all_participants = array_merge($all_participants, explode(',', $msg['destinatario_email']));
        }
        if (!empty($msg['cc_emails'])) {
            $all_participants = array_merge($all_participants, explode(',', $msg['cc_emails']));
        }
        if (!empty($msg['bcc_emails'])) { 
            $all_participants = array_merge($all_participants, explode(',', $msg['bcc_emails']));
        }
    }
}
$unique_participants = array_unique(array_filter(array_map('trim', $all_participants)));
$unique_participants = array_filter($unique_participants, function($email) {
    return strtolower($email) !== strtolower(SMTP_USER); 
});

// 6. Busca todos os usuários para o dropdown de atribuição
$stmt_users = $conn->prepare("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
if (!$stmt_users) { error_log("Erro ao preparar query de usuários: " . $conn->error); }
$stmt_users->execute();
$all_users = $stmt_users->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_users->close();

// 7. Busca todas as categorias ativas para os dropdowns
$stmt_categories = $conn->prepare("SELECT id, nome FROM categorias_chamado WHERE ativa = 1 ORDER BY nome ASC");
if (!$stmt_categories) { error_log("Erro ao preparar query de categorias: " . $conn->error); }
$stmt_categories->execute();
$all_categories = $stmt_categories->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_categories->close();

// 8. Função auxiliar para formatar o tamanho do arquivo
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamado #<?php echo htmlspecialchars($chamado_id); ?> - <?php echo htmlspecialchars($chamado['assunto'] ?? 'N/A'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="tinymce/js/tinymce/tinymce.min.js"></script>
    <style>
        /* Estilos Gerais do Corpo e Contêineres */
        body { background-color: #e9ecef; }
        .container { margin-top: 2rem; margin-bottom: 2rem; }
        .chat-container { padding: 20px; background-color: #ffffff; border-radius: 10px; border: 1px solid #dee2e6; }

        /* Estilos das Mensagens */
        .message-card { max-width: 85%; border-radius: 15px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .message-recebido .message-card { background-color: #f1f3f5; border-top-left-radius: 0; }
        .message-enviado .message-card { background-color: #dcf8c6; border-top-right-radius: 0; }
        .message-interno .message-card { background-color: #fff3cd; border-radius: 15px; }
        .avatar { font-size: 2.5rem; color: #6c757d; }
        .email-body {
            background-color: transparent;
            border: none;
            padding: 0;
            max-height: 600px;
            overflow-y: auto;
            word-wrap: break-word; /* Garante que palavras longas quebrem para caber no contêiner */
            overflow-x: auto;     /* Adiciona uma barra de rolagem horizontal se o conteúdo (ex: tabelas) for muito largo */
            -webkit-overflow-scrolling: touch; /* Melhora a rolagem em dispositivos iOS */
            min-width: 0; /* Permite que o contêiner encolha, crucial para flexbox/grid com conteúdo largo */
        }
        /* NOVO: Regras para todos os elementos dentro do email-body para garantir que se ajustem */
        .email-body * { 
            box-sizing: border-box; /* Garante que padding e border sejam incluídos na largura/altura */
            max-width: 100% !important; /* SOBRESCREVE QUALQUER LARGURA FIXA E GARANTE RESPONSIVIDADE */
            height: auto !important; /* Garante que a altura se ajuste proporcionalmente à largura */
            word-wrap: break-word; /* Repete para garantir em elementos aninhados */
            overflow-wrap: break-word; /* Versão moderna de word-wrap para compatibilidade */
        }
        .email-body table {
            width: 100% !important; /* Força tabelas a 100% de largura */
            table-layout: fixed;    /* Ajuda o navegador a renderizar tabelas mais rapidamente e a respeitar larguras */
        }
        .email-body td,
        .email-body th {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .email-body img {
            max-width: 100% !important;
            height: auto !important;
            display: block; /* Garante que imagens se comportem como blocos para controle de largura/altura */
        }
        .email-body pre,
        .email-body code {
            white-space: pre-wrap; /* Quebra linhas em blocos de código e preformatados */
            word-wrap: break-word; /* Quebra palavras longas dentro de blocos de código */
        }
        /* FIM DAS ADIÇÕES PARA Lidar com texto e conteúdo largo */

        .card-footer { background-color: transparent; border-top: 1px solid rgba(0,0,0,0.05); }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .message-row { margin-bottom: 20px; opacity: 0; animation: fadeInUp 0.5s ease-out forwards; }

        /* Estilos de Participantes e Cursors */
        .participant-email { cursor: pointer; }
        .participant-email:hover { background-color: #d0e3ff !important; }
        
        /* Estilos para os badges de status */
        .status-aberto { background-color: #d1e7dd; color: #0f5132; }
        .status-pendente { background-color: #fff3cd; color: #664d03; }
        .status-fechado { background-color: #f8d7da; color: #842029; }

        /* --- Estilos do Overlay de Carregamento --- */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.75);
            display: none; /* Por padrão, OCULTO. Só aparece via JS. */
            justify-content: center;
            align-items: center;
            z-index: 1050; /* Garante que está acima de tudo */
            color: white;
            text-align: center;
            backdrop-filter: blur(5px);
            opacity: 0; 
            transition: opacity 0.3s ease-in-out; 
        }
        /* NOVO: Regra mais agressiva para forçar exibição e transição */
        #loading-overlay.show-overlay { 
            display: flex !important; /* Força a exibição como flex */
            opacity: 1 !important;   /* Força opacidade total */
        }
        .loading-box {
            background-color: #34495e;
            padding: 30px 40px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 400px;
            display: flex; /* Para centralizar o conteúdo dentro da caixa */
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .progress-bar-container {
            width: 100%;
            background-color: #555;
            border-radius: 5px;
            margin-top: 20px;
            overflow: hidden;
        }
        .progress-bar-inner {
            height: 20px;
            width: 0%;
            background: linear-gradient(90deg, rgba(46,204,113,1) 0%, rgba(39,174,96,1) 100%);
            border-radius: 5px;
            transition: width 2.5s ease-in-out;
        }
    </style>
</head>
<body>
    <div class="header-sticky-wrapper">
        <div class="container-fluid"> 
            <div class="bg-light p-3 rounded-top shadow-sm header-main-content">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar</a>
                    <div class="text-center">
                        <h4 class="mb-0 text-truncate mx-3">
                            Chamado #<?php echo htmlspecialchars($chamado_id); ?>: <?php echo htmlspecialchars($chamado['assunto'] ?? 'N/A'); ?>
                            <span class="badge rounded-pill status-<?php echo strtolower($chamado['status'] ?? ''); ?> ms-2">
                                <?php echo htmlspecialchars($chamado['status'] ?? 'N/A'); ?>
                            </span>
                        </h4>
                        <small class="text-muted">
                            Atribuído a: <strong><?php echo htmlspecialchars($chamado['nome_responsavel'] ?? 'Ninguém'); ?></strong>
                            <?php if (!empty($chamado['nome_ultimo_atualizador_msg'])): ?>
                                &bull; Última interação por: <strong><?php echo htmlspecialchars($chamado['nome_ultimo_atualizador_msg']); ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($chamado['nome_categoria'])): ?>
                                &bull; Categoria: <strong><?php echo htmlspecialchars($chamado['nome_categoria']); ?></strong>
                            <?php else: ?>
                                &bull; Categoria: <strong>Não Definida</strong>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div style="width: 95px;"></div> 
                </div>
            </div>

            <?php if (!empty($unique_participants)): ?>
            <div class="card rounded-0 rounded-bottom shadow-sm header-collapsible-section">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-title text-muted mb-0">Participantes do Chamado</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="copyAllParticipants()">
                            <i class="bi bi-copy"></i> Copiar Todos para Cc
                        </button>
                    </div>
                    <hr class="my-2">
                    <div id="participants-list">
                        <?php foreach ($unique_participants as $participant): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1 p-2 participant-email" onclick="addParticipantToCc('<?php echo htmlspecialchars($participant); ?>')">
                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($participant); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div> </div> <div class="container mt-5 mb-5"> 
        <div class="row">
            <div class="col-md-12">
                <div class="chat-container">
                <?php foreach ($mensagens as $index => $msg): ?>
                    <?php
                    $is_enviado = ($msg['tipo'] == 'enviado');
                    $is_interno = ($msg['tipo'] == 'interno');
                    
                    $row_class = 'message-recebido justify-content-start';
                    $avatar_icon = 'bi-person-circle';
                    if ($is_enviado) {
                        $row_class = 'message-enviado justify-content-end';
                        $avatar_icon = 'bi-headset';
                    } elseif ($is_interno) {
                        $row_class = 'message-interno justify-content-center';
                        $avatar_icon = 'bi-info-circle-fill';
                    }

                    // Busca anexos e imagens da mensagem
                    $stmt_anexos = $conn->prepare("SELECT id, nome_arquivo, tamanho_bytes FROM anexos WHERE mensagem_id = ?");
                    if (!$stmt_anexos) { error_log("Erro na preparação da query de anexos: " . $conn->error); continue; }
                    $stmt_anexos->bind_param("i", $msg['id']);
                    if (!$stmt_anexos->execute()) { error_log("Erro na execução da query de anexos: " . $stmt_anexos->error); continue; }
                    $anexos = $stmt_anexos->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt_anexos->close();
                    
                    $corpo_final = $msg['corpo_html'] ? $msg['corpo_html'] : '<pre>' . htmlspecialchars($msg['corpo_texto'] ?? '') . '</pre>';
                    $corpo_final = preg_replace_callback('/src="cid:(.*?)"/i', function($matches) use ($conn, $msg) {
                        $cid = $matches[1];
                        $stmt_cid = $conn->prepare("SELECT id FROM imagens_embutidas WHERE mensagem_id = ? AND content_id = ?");
                        if (!$stmt_cid) { error_log("Erro na preparação da query de imagens embutidas: " . $conn->error); return $matches[0]; }
                        $stmt_cid->bind_param("is", $msg['id'], $cid);
                        if (!$stmt_cid->execute()) { error_log("Erro na execução da query de imagens embutidas: " . $stmt_cid->error); return $matches[0]; }
                        $result = $stmt_cid->get_result()->fetch_assoc();
                        $stmt_cid->close();
                        return $result ? 'src="get_asset.php?type=image&id=' . $result['id'] . '"' : $matches[0];
                    }, $corpo_final);
                    ?>
                    
                    <div class="d-flex message-row <?php echo $row_class; ?>" style="animation-delay: <?php echo $index * 0.15; ?>s;">
                        <?php if (!$is_enviado && !$is_interno): ?><div class="avatar me-3 align-self-start"><i class="bi bi-person-circle"></i></div><?php endif; ?>
                        <div class="card message-card" id="message-<?php echo $msg['id']; ?>" 
                             data-sender-email="<?php echo htmlspecialchars($msg['remetente_email'] ?? ''); ?>" 
                             data-to-emails="<?php echo htmlspecialchars($msg['destinatario_email'] ?? ''); ?>" 
                             data-cc-emails="<?php echo htmlspecialchars($msg['cc_emails'] ?? ''); ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong class="card-title"><i class="bi <?php echo $avatar_icon; ?> me-2"></i><?php echo htmlspecialchars($msg['remetente_nome'] ?? 'N/A'); ?></strong>
                                    <div>
                                        <small class="text-muted me-2"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($msg['data_envio'] ?? 'now'))); ?></small>
                                        <?php if (!$is_interno): ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="quoteMessage(<?php echo $msg['id']; ?>)" title="Responder a esta mensagem"><i class="bi bi-reply-fill"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <hr class="my-2">
                                <?php if (!$is_interno): ?>
                                <div class="mb-2" style="font-size: 0.9em;">
                                    <strong>De:</strong> <?php echo htmlspecialchars($msg['remetente_email'] ?? ''); ?><br>
                                    <strong>Para:</strong> <?php echo htmlspecialchars($msg['destinatario_email'] ?? ''); ?>
                                    <?php if (!empty($msg['cc_emails'])): ?><br><strong>Cc:</strong> <?php echo htmlspecialchars($msg['cc_emails']); ?><?php endif; ?>
                                    <?php if (!empty($msg['bcc_emails'])): ?><br><strong>Cco:</strong> <?php echo htmlspecialchars($msg['bcc_emails']); ?><?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="email-body"><?php echo $corpo_final; ?></div>
                            </div>
                            <?php if (!empty($anexos)): ?>
                            <div class="card-footer">
                                <strong>Anexos:</strong>
                                <?php foreach ($anexos as $anexo): ?>
                                    <a href="get_asset.php?type=attachment&id=<?php echo $anexo['id']; ?>" class="btn btn-sm btn-outline-secondary me-2 mt-1">
                                        <i class="bi bi-paperclip"></i> 
                                        <?php echo htmlspecialchars($anexo['nome_arquivo'] ?? 'N/A'); ?> 
                                        <small class="text-muted">(<?php echo formatBytes($anexo['tamanho_bytes'] ?? 0); ?>)</small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_enviado): ?><div class="avatar ms-3 align-self-start"><i class="bi bi-headset"></i></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mt-4" id="reply-section">
            <div class="card-header d-flex justify-content-between align-items-center">
                <ul class="nav nav-tabs card-header-tabs" id="action-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" id="responder-tab" data-bs-toggle="tab" data-bs-target="#responder-pane" type="button" role="tab" aria-controls="responder-pane" aria-selected="true">Responder ao Cliente</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" id="nota-tab" data-bs-toggle="tab" data-bs-target="#nota-pane" type="button" role="tab" aria-controls="nota-pane" aria-selected="false">Adicionar Nota Interna (TROCA DE USUÁRIO E STATUS)</button></li>
                </ul>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#replyCollapseContent" aria-expanded="true" aria-controls="replyCollapseContent" id="toggleReplySection">
                    <i class="bi bi-chevron-compact-up"></i> Ocultar
                </button>
            </div>
            <div class="collapse show" id="replyCollapseContent">
                <div class="card-body tab-content" id="action-tabs-content">
                    <div class="tab-pane fade show active" id="responder-pane" role="tabpanel" aria-labelledby="responder-tab">
                        <form action="send_reply.php" method="post" enctype="multipart/form-data" id="reply-form">
                            <input type="hidden" name="chamado_id" value="<?php echo htmlspecialchars($chamado_id); ?>">
                            <div class="row">
                                <div class="col-md-12 mb-3"><label for="to_emails" class="form-label fw-bold">Para:</label><input type="text" class="form-control" name="to_emails" id="to_emails" value="<?php echo htmlspecialchars($chamado['email_cliente'] ?? ''); ?>" required></div>
                                <div class="col-md-6 mb-3"><label for="cc_emails" class="form-label">Cc:</label><input type="text" class="form-control" name="cc_emails" id="cc_emails"></div>
                                <div class="col-md-6 mb-3"><label for="bcc_emails" class="form-label">Cco:</label><input type="text" class="form-control" name="bcc_emails" id="bcc_emails"></div>
                            </div>
                            <div class="mb-3"><label for="corpo_resposta" class="form-label fw-bold">Mensagem:</label><textarea name="corpo_resposta" id="corpo_resposta"></textarea></div>
                            <div class="mb-3"><label for="anexos" class="form-label">Anexos:</label><input class="form-control" type="file" name="anexos[]" id="anexos" multiple></div>
                            <div class="p-3 rounded" style="background-color: #e9f5ff;">
                                <h6 class="mb-3 text-primary">Opções de Envio e Atualização</h6>
                                <div class="row align-items-end">
                                    <div class="col-md-4">
                                        <label for="reply_status" class="form-label"><strong>Status:</strong></label>
                                        <select name="status" id="reply_status" class="form-select">
                                            <option value="Aberto" <?php if(($chamado['status'] ?? '') == 'Aberto') echo 'selected'; ?>>Aberto</option>
                                            <option value="Pendente" <?php if(($chamado['status'] ?? '') == 'Pendente') echo 'selected'; ?>>Pendente</option>
                                            <option value="Fechado" <?php if(($chamado['status'] ?? '') == 'Fechado') echo 'selected'; ?>>Fechado</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="reply_prioridade" class="form-label"><strong>Prioridade:</strong></label>
                                        <select name="prioridade" id="reply_prioridade" class="form-select">
                                            <option value="Baixa" <?php if(($chamado['prioridade'] ?? '') == 'Baixa') echo 'selected'; ?>>Baixa</option>
                                            <option value="Media" <?php if(($chamado['prioridade'] ?? '') == 'Media') echo 'selected'; ?>>Média</option>
                                            <option value="Alta" <?php if(($chamado['prioridade'] ?? '') == 'Alta') echo 'selected'; ?>>Alta</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="reply_categoria_id" class="form-label"><strong>Categoria:</strong></label>
                                        <select class="form-select" id="reply_categoria_id" name="categoria_id">
                                            <option value="">-- Manter Categoria Atual --</option>
                                            <?php foreach ($all_categories as $category): ?>
                                                <option value="<?php echo $category['id']; ?>" <?php if(($chamado['categoria_id'] ?? '') == $category['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($category['nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <button type="submit" class="btn btn-success w-100" id="submit-reply-btn">Enviar Resposta e Atualizar</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="nota-pane" role="tabpanel" aria-labelledby="nota-tab">
                        <form action="process_internal_note.php" method="post" id="note-form">
                            <input type="hidden" name="chamado_id" value="<?php echo htmlspecialchars($chamado_id); ?>">
                            <div class="mb-3">
                                <label for="status_nota" class="form-label fw-bold">Alterar Status (Opcional):</label>
                                <select class="form-select" id="status_nota" name="status_nota">
                                    <option value="">-- Manter status atual --</option>
                                    <option value="Aberto" <?php if(($chamado['status'] ?? '') == 'Aberto') echo 'selected'; ?>>Aberto</option>
                                    <option value="Pendente" <?php if(($chamado['status'] ?? '') == 'Pendente') echo 'selected'; ?>>Pendente</option>
                                    <option value="Fechado" <?php if(($chamado['status'] ?? '') == 'Fechado') echo 'selected'; ?>>Fechado</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="nota_interna" class="form-label fw-bold">Nota Interna (não será enviada ao cliente):</label>
                                <textarea name="nota_interna" id="nota_interna" class="form-control" rows="5" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="novo_usuario_id" class="form-label"><strong>Reatribuir Chamado para:</strong> (Opcional)</label>
                                <select name="novo_usuario_id" id="novo_usuario_id" class="form-select">
                                    <option value="">-- Manter responsável atual (<?php echo htmlspecialchars($chamado['nome_responsavel'] ?? 'Ninguém'); ?>) --</option>
                                    <?php foreach ($all_users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php if(($chamado['usuario_id'] ?? '') == $user['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($user['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="nota_categoria_id" class="form-label"><strong>Alterar Categoria:</strong> (Opcional)</label>
                                <select class="form-select" id="nota_categoria_id" name="categoria_id">
                                    <option value="">-- Manter Categoria Atual --</option>
                                    <?php foreach ($all_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php if(($chamado['categoria_id'] ?? '') == $category['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($category['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-info" id="submit-note-btn">Salvar Nota Interna</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="loading-overlay">
        <div class="loading-box">
            <h4>Enviando...</h4>
            <p>Por favor, aguarde.</p>
            <div class="progress-bar-container">
                <div class="progress-bar-inner"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // NOVO: Define a assinatura do usuário em uma variável JavaScript
    const userSignature = <?php echo json_encode($user_signature_html ?? ''); ?>;

    tinymce.init({
        selector: 'textarea#corpo_resposta',
        language: 'pt_BR',
        promotion: false,
        plugins: [
    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
    'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
    'insertdatetime', 'media', 'table', 'help', 'wordcount'
     // REMOVIDOS: 'powerpaste', 'casechange', 'export'
  ],
        toolbar: 'fullscreen |undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat  ',
        setup: function (editor) {
            editor.on('init', function (e) {
                // Se não houver conteúdo ou se for a primeira carga, adicione a assinatura
                if (editor.getContent().trim() === '') {
                    editor.setContent('<p>&nbsp;</p>' + (userSignature || '')); // Adiciona a assinatura se houver
                }
            });
        }
    });
    
    function quoteMessage(messageId) {
        const messageCard = document.getElementById('message-' + messageId);
        if (!messageCard) return;
        const sender = messageCard.dataset.senderEmail;
        const toList = messageCard.dataset.toEmails.split(',').map(e => e.trim()).filter(e => e);
        const ccList = (messageCard.dataset.ccEmails || '').split(',').map(e => e.trim()).filter(e => e);
        const supportEmail = '<?php echo SMTP_USER; ?>';
        document.getElementById('to_emails').value = sender;
        let allRecipients = [...toList, ...ccList];
        let ccRecipients = allRecipients.filter(email => email !== supportEmail && email !== sender);
        let uniqueCc = [...new Set(ccRecipients)];
        document.getElementById('cc_emails').value = uniqueCc.join(', '); 
        const senderName = messageCard.querySelector('.card-title').innerText;
        const date = messageCard.querySelector('.text-muted').innerText;
        const body = messageCard.querySelector('.email-body').innerHTML;
        const quoteHtml = `<p>&nbsp;</p><blockquote class="mce-blockquote" style="border-left: 2px solid #ccc; margin-left: 5px; padding-left: 10px; color: #666;"><p>Em ${date}, <strong>${senderName}</strong> escreveu:</p>${body}</blockquote><p>&nbsp;</p>`;
        const editor = tinymce.get('corpo_resposta');
        if (editor) {
            // NOVO: Adiciona a citação e então a assinatura
            editor.setContent(quoteHtml + (userSignature || '')); // Anexa a assinatura após a citação
            editor.focus();
            const replySection = document.getElementById('reply-section');
            replySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function copyAllParticipants() {
        const participantBadges = document.querySelectorAll('#participants-list .participant-email');
        const emails = Array.from(participantBadges).map(badge => badge.innerText.trim());
        const mainRecipient = document.getElementById('to_emails').value.trim();
        const ccEmails = emails.filter(email => email !== mainRecipient);
        const ccField = document.getElementById('cc_emails');
        // A variável currentCc não está definida neste escopo, causando erro.
        // O correto seria usar ccEmails diretamente aqui, como abaixo:
        ccField.value = ccEmails.join(', '); 
        const replySection = document.getElementById('reply-section');
        replySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        ccField.focus();
    }

    function addParticipantToCc(emailToAdd) {
        const ccField = document.getElementById('cc_emails');
        const mainRecipient = document.getElementById('to_emails').value.trim();
        if (emailToAdd === mainRecipient) { return; }
        let currentCc = ccField.value.split(',').map(e => e.trim()).filter(e => e);
        if (!currentCc.includes(emailToAdd)) {
            currentCc.push(emailToAdd);
            ccField.value = currentCc.join(', ');
        }
        const replySection = document.getElementById('reply-section');
        replySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        ccField.focus();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const replyForm = document.getElementById('reply-form');
        const noteForm = document.getElementById('note-form');
        const loadingOverlay = document.getElementById('loading-overlay');
        const progressBar = document.querySelector('.progress-bar-inner');
        
        function showLoading(event) {
            loadingOverlay.classList.add('show-overlay'); // MODIFICADO: Usa classList.add
            setTimeout(function() { progressBar.style.width = '100%'; }, 100); 
            event.target.querySelector('button[type="submit"]').disabled = true;
        }

        if (replyForm) { replyForm.addEventListener('submit', showLoading); }
        if (noteForm) { noteForm.addEventListener('submit', showLoading); }

        // JavaScript para o ícone do botão de recolher/expandir a seção de resposta
        const replyCollapseContent = document.getElementById('replyCollapseContent');
        const toggleReplySectionBtn = document.getElementById('toggleReplySection');

        if (replyCollapseContent && toggleReplySectionBtn) {
            // Verifica o estado inicial e define o texto/ícone corretos
            if (replyCollapseContent.classList.contains('show')) {
                toggleReplySectionBtn.innerHTML = '<i class=\"bi bi-chevron-compact-up\"></i> Ocultar';
            } else {
                toggleReplySectionBtn.innerHTML = '<i class=\"bi bi-chevron-compact-down\"></i> Exibir';
            }

            replyCollapseContent.addEventListener('show.bs.collapse', function () {
                toggleReplySectionBtn.innerHTML = '<i class=\"bi bi-chevron-compact-up\"></i> Ocultar';
            });
            replyCollapseContent.addEventListener('hide.bs.collapse', function () {
                toggleReplySectionBtn.innerHTML = '<i class=\"bi bi-chevron-compact-down\"></i> Exibir';
            });
        }
    });


    // JavaScript para o efeito de redução do topo
    window.addEventListener('scroll', function() {
        const headerWrapper = document.querySelector('.header-sticky-wrapper');
        const headerMainContent = document.querySelector('.header-main-content');
        const headerCollapsibleSection = document.querySelector('.header-collapsible-section');
        const scrollThreshold = 100; // Distância de rolagem para ativar o efeito

        if (window.scrollY > scrollThreshold) {
            headerWrapper.classList.add('scrolled');
            headerMainContent.classList.add('scrolled');
            if (headerCollapsibleSection) { // Garante que a seção exista antes de tentar adicionar a classe
                headerCollapsibleSection.classList.add('scrolled');
            }
        } else {
            headerWrapper.classList.remove('scrolled');
            headerMainContent.classList.remove('scrolled');
            if (headerCollapsibleSection) { // Garante que a seção exista antes de tentar remover a classe
                headerCollapsibleSection.classList.remove('scrolled');
            }
        }
    });
</script>
</body>
</html>