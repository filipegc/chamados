<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// Apenas administradores podem acessar esta página
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado.');
    exit;
}

$message = '';
// Array para armazenar todas as configurações
$configs = [
    'auto_reply_enabled' => 0,
    'auto_reply_message' => '',
    'support_sender_name' => 'Equipe de Suporte',
    'auto_reply_subject_prefix' => 'Confirmação de Chamado',
    'email_fetch_start_date' => '',
    'last_fetch_timestamp' => '',
    'auto_fetch_interval_minutes' => '10'
];

// --- Lógica para Salvar Configurações ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Coleta os valores do formulário
    $configs['auto_reply_enabled'] = isset($_POST['auto_reply_enabled']) ? 1 : 0;
    $configs['auto_reply_message'] = $_POST['auto_reply_message'] ?? '';
    $configs['support_sender_name'] = trim($_POST['support_sender_name'] ?? '');
    $configs['auto_reply_subject_prefix'] = trim($_POST['auto_reply_subject_prefix'] ?? '');
    $configs['email_fetch_start_date'] = $_POST['email_fetch_start_date'] ?? '';
    $configs['auto_fetch_interval_minutes'] = (int)($_POST['auto_fetch_interval_minutes'] ?? 10);
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO sistema_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
        if (!$stmt) {
            throw new Exception("Erro ao preparar query: " . $conn->error);
        }

        foreach ($configs as $key => $value) {
            if ($key !== 'last_fetch_timestamp') {
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
            }
        }
        
        if (isset($_POST['email_fetch_start_date'])) {
            $key_reset = 'last_fetch_timestamp';
            $value_reset = '';
            $stmt->bind_param("ss", $key_reset, $value_reset);
            $stmt->execute();
        }
        
        $stmt->close();
        $conn->commit();
        $message = "<div class='alert alert-success'>Configurações salvas com sucesso!</div>";

    } catch (Exception $e) {
        $conn->rollback();
        $message = "<div class='alert alert-danger'>Erro ao salvar configurações: " . $e->getMessage() . "</div>";
    }
}

// --- Lógica para Carregar Configurações Atuais ---
try {
    $result = $conn->query("SELECT config_key, config_value FROM sistema_config");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isset($configs[$row['config_key']])) {
                $configs[$row['config_key']] = $row['config_value'];
            }
        }
    }
} catch (mysqli_sql_exception $e) {
    $message .= "<div class='alert alert-danger'>Erro ao carregar configurações: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações do Sistema - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <script src="tinymce/js/tinymce/tinymce.min.js"></script>
    <style>
        .tag-button {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container mt-5 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Configurações do Sistema</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar</a>
        </div>

        <?php echo $message; ?>

        <form action="manage_auto_reply.php" method="POST">
            <div class="card mb-4">
                <div class="card-header"><h5><i class="bi bi-gear-wide-connected me-2"></i>Automação e Coleta</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="auto_fetch_interval_minutes" class="form-label fw-bold">Intervalo da Coleta (Minutos)</label>
                            <input type="number" class="form-control" id="auto_fetch_interval_minutes" name="auto_fetch_interval_minutes" value="<?php echo htmlspecialchars($configs['auto_fetch_interval_minutes']); ?>" min="1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email_fetch_start_date" class="form-label fw-bold">Coletar E-mails a Partir de (Data Base)</label>
                            <input type="date" class="form-control" id="email_fetch_start_date" name="email_fetch_start_date" value="<?php echo htmlspecialchars($configs['email_fetch_start_date']); ?>">
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <p class="mb-0 text-muted small"><i class="bi bi-info-circle-fill"></i> <strong>Última Coleta:</strong> <?php echo !empty($configs['last_fetch_timestamp']) ? date('d/m/Y H:i:s', strtotime($configs['last_fetch_timestamp'])) : 'Nenhuma coleta automática registrada.'; ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-robot me-2"></i>Configurações da Resposta Automática</h5></div>
                <div class="card-body">
                    <div class="mb-3 form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" role="switch" id="auto_reply_enabled" name="auto_reply_enabled" value="1" <?php echo (int)$configs['auto_reply_enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="auto_reply_enabled">Ativar Resposta Automática Global</label>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="support_sender_name" class="form-label fw-bold">Nome do Remetente</label>
                            <input type="text" class="form-control" id="support_sender_name" name="support_sender_name" value="<?php echo htmlspecialchars($configs['support_sender_name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="auto_reply_subject_prefix" class="form-label fw-bold">Prefixo do Assunto do E-mail</label>
                            <input type="text" class="form-control" id="auto_reply_subject_prefix" name="auto_reply_subject_prefix" value="<?php echo htmlspecialchars($configs['auto_reply_subject_prefix']); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="auto_reply_message" class="form-label fw-bold">Conteúdo da Mensagem</label>
                        
                        <div class="mb-2 p-2 bg-light border rounded">
                            <small class="form-text text-muted">Clique para adicionar variáveis ao texto:</small><br>
                            <button type="button" class="btn btn-sm btn-outline-secondary tag-button" data-tag="{{nome_cliente}}">Nome do Cliente</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary tag-button" data-tag="{{numero_chamado}}">Nº do Chamado</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary tag-button" data-tag="{{assunto_chamado}}">Assunto</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary tag-button" data-tag="{{data_abertura}}">Data de Abertura</button>
                        </div>
                        
                        <textarea name="auto_reply_message" id="auto_reply_message" class="form-control" rows="10"><?php echo htmlspecialchars($configs['auto_reply_message']); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Salvar Configurações</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        tinymce.init({
            selector: 'textarea#auto_reply_message',
            language: 'pt_BR',
            promotion: false, // Remove a propaganda
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
            toolbar: 'fullscreen | undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
            branding: false, // Remove a marca d'água
            height: 300 
        });

        // Script para inserir as tags no editor
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.tag-button').forEach(button => {
                button.addEventListener('click', function() {
                    const tag = this.getAttribute('data-tag');
                    tinymce.get('auto_reply_message').execCommand('mceInsertContent', false, tag);
                });
            });
        });
    </script>
</body>
</html>