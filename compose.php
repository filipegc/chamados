<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// NOVO: Busca todas as categorias ativas para o dropdown
$stmt_categories = $conn->prepare("SELECT id, nome FROM categorias_chamado WHERE ativa = 1 ORDER BY nome ASC");
$stmt_categories->execute();
$all_categories = $stmt_categories->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_categories->close();

// NOVO: Busca a assinatura do usuário logado para pré-preencher o editor TinyMCE
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
        error_log("Erro ao preparar query de assinatura do usuário em compose.php: " . $conn->error);
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Chamado - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
   <script src="tinymce/js/tinymce/tinymce.min.js"></script>
    <style>
        /* Estilos do overlay de carregamento (copiados de view_ticket.php) */
        #loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.75); display: none; justify-content: center; align-items: center; z-index: 9999; color: white; text-align: center; backdrop-filter: blur(5px); }
        .loading-box { background-color: #34495e; padding: 30px 40px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.3); width: 90%; max-width: 400px; }
        .progress-bar-container { width: 100%; background-color: #555; border-radius: 5px; margin-top: 20px; overflow: hidden; }
        .progress-bar-inner { height: 20px; width: 0%; background: linear-gradient(90deg, rgba(46,204,113,1) 0%, rgba(39,174,96,1) 100%); border-radius: 5px; transition: width 2.5s ease-in-out; }
    </style>
</head>
<body>
    <div class="container mt-5 mb-5">
        <a href="index.php" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para a Lista</a>
        <div class="card">
            <div class="card-header">
                <h3>Criar Novo Chamado</h3>
            </div>
            <div class="card-body">
                <form action="process_compose.php" method="post" enctype="multipart/form-data" id="compose-form">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="to_emails" class="form-label fw-bold">Para:</label>
                            <input type="text" class="form-control" name="to_emails" id="to_emails" placeholder="Endereço de e-mail do destinatário" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="cc_emails" class="form-label">Cc (Com Cópia):</label>
                            <input type="text" class="form-control" name="cc_emails" id="cc_emails" placeholder="Endereços de e-mail separados por vírgula">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="bcc_emails" class="form-label">Cco (Cópia Oculta):</label>
                            <input type="text" class="form-control" name="bcc_emails" id="bcc_emails" placeholder="Endereços de e-mail separados por vírgula">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="subject" class="form-label fw-bold">Assunto:</label>
                        <input type="text" class="form-control" name="subject" id="subject" required>
                    </div>
                    <div class="mb-3">
                        <label for="categoria_id" class="form-label fw-bold">Categoria:</label>
                        <select class="form-select" id="categoria_id" name="categoria_id" required>
                            <option value="">-- Selecione uma Categoria --</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="corpo_mensagem" class="form-label fw-bold">Mensagem:</label>
                        <textarea name="corpo_mensagem" id="corpo_mensagem"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="anexos" class="form-label">Anexos:</label>
                        <input class="form-control" type="file" name="anexos[]" id="anexos" multiple>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" id="submit-compose-btn">
                            <i class="bi bi-send"></i> Enviar e Abrir Chamado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="loading-overlay">
        <div class="loading-box">
            <h4>Enviando e-mail e criando chamado...</h4>
            <p>Por favor, aguarde.</p>
            <div class="progress-bar-container">
                <div class="progress-bar-inner"></div>
            </div>
        </div>
    </div>

<script>
    // NOVO: Define a assinatura do usuário em uma variável JavaScript
    const userSignatureCompose = <?php echo json_encode($user_signature_html ?? ''); ?>;

 
    tinymce.init({
        // ATENÇÃO: Verifique se o nome do campo é 'assinatura'.
        // Se for diferente, ajuste o seletor abaixo.
      selector: 'textarea#corpo_mensagem',
        promotion: false,
        language: 'pt_BR',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'fullscreen | undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
        
        // Remove a borda "Powered by Tiny"
        branding: false, 
        
        // Define uma altura padrão para o editor
        height: 250 
    });


    // Lógica para a animação de envio
    document.addEventListener('DOMContentLoaded', function() {
        const composeForm = document.getElementById('compose-form');
        const submitButton = document.getElementById('submit-compose-btn');
        const loadingOverlay = document.getElementById('loading-overlay');
        const progressBar = document.querySelector('.progress-bar-inner');

        if (composeForm) {
            composeForm.addEventListener('submit', function(event) {
                loadingOverlay.style.display = 'flex';
                setTimeout(function() {
                    progressBar.style.width = '100%';
                }, 100); 
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enviando...';
            });
        }
    });
</script>
</body>
</html>