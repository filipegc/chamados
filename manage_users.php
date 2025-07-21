<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado.');
    exit;
}

$message = '';
$edit_user = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $nome = trim($_POST['nome']);
        $email = trim(strtolower($_POST['email']));
        $senha = $_POST['senha'] ?? '';
        $ativo = isset($_POST['ativa']) ? 1 : 0;
        $role = $_POST['role'] ?? 'atendente';
        $signature_html = $_POST['signature_html'] ?? null;
        // NOVO: Captura a nova configuração do formulário.
        $email_sender_name_type = $_POST['email_sender_name_type'] ?? 'real_name';

        if (empty($nome) || empty($email)) {
            $message = "<div class='alert alert-danger'>Nome e E-mail são obrigatórios!</div>";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "<div class='alert alert-danger'>Formato de e-mail inválido!</div>";
        } else {
            if ($action === 'add') {
                if (empty($senha)) {
                    $message = "<div class='alert alert-danger'>A senha é obrigatória para novos usuários!</div>";
                } else {
                    $hashed_password = password_hash($senha, PASSWORD_DEFAULT);
                    // ALTERADO: Adiciona a nova coluna 'email_sender_name_type' na query.
                    $stmt = $conn->prepare("INSERT INTO usuarios (nome, email, senha, ativo, role, email_sender_name_type, signature_html) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        // ALTERADO: Adiciona o tipo 's' e a variável para a nova coluna.
                        $stmt->bind_param("sssisss", $nome, $email, $hashed_password, $ativo, $role, $email_sender_name_type, $signature_html);
                        if ($stmt->execute()) {
                            $message = "<div class='alert alert-success'>Usuário '<strong>" . htmlspecialchars($nome) . "</strong>' adicionado com sucesso!</div>";
                        } else {
                            if ($conn->errno == 1062) {
                                $message = "<div class='alert alert-danger'>Erro: O e-mail '<strong>" . htmlspecialchars($email) . "</strong>' já está em uso.</div>";
                            } else {
                                $message = "<div class='alert alert-danger'>Erro ao adicionar usuário: " . $stmt->error . "</div>";
                            }
                        }
                        $stmt->close();
                    } else {
                        $message = "<div class='alert alert-danger'>Erro ao preparar declaração de adição: " . $conn->error . "</div>";
                    }
                }
            } elseif ($action === 'edit') {
                $user_id = (int)$_POST['user_id'];
                
                // ... (a lógica de validação do único admin continua a mesma) ...

                if (empty($message)) {
                    // ALTERADO: Inclui 'email_sender_name_type' na query UPDATE.
                    $sql = "UPDATE usuarios SET nome = ?, email = ?, ativo = ?, role = ?, email_sender_name_type = ?, signature_html = ?";
                    $types = "ssisss";
                    $params = [$nome, $email, $ativo, $role, $email_sender_name_type, $signature_html];

                    if (!empty($senha)) {
                        $hashed_password = password_hash($senha, PASSWORD_DEFAULT);
                        $sql .= ", senha = ?";
                        $types .= "s";
                        $params[] = $hashed_password;
                    }
                    $sql .= " WHERE id = ?";
                    $types .= "i";
                    $params[] = $user_id;

                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        if ($stmt->execute()) {
                            $message = "<div class='alert alert-success'>Usuário '<strong>" . htmlspecialchars($nome) . "</strong>' atualizado com sucesso!</div>";
                            if ($user_id === $_SESSION['usuario_id']) {
                                $_SESSION['usuario_nome'] = $nome;
                                $_SESSION['usuario_role'] = $role;
                                $_SESSION['usuario_signature'] = $signature_html;
                            }
                        } else {
                            if ($conn->errno == 1062) {
                                $message = "<div class='alert alert-danger'>Erro: O e-mail '<strong>" . htmlspecialchars($email) . "</strong>' já está em uso por outro usuário.</div>";
                            } else {
                                $message = "<div class='alert alert-danger'>Erro ao atualizar usuário: " . $stmt->error . "</div>";
                            }
                        }
                        $stmt->close();
                    } else {
                        $message = "<div class='alert alert-danger'>Erro ao preparar declaração de edição: " . $conn->error . "</div>";
                    }
                }
            }
        }
    } 
    // ... (a lógica de exclusão continua a mesma) ...
}

if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    // ALTERADO: Inclui a nova coluna na query SELECT para edição.
    $stmt = $conn->prepare("SELECT id, nome, email, ativo, role, email_sender_name_type, signature_html FROM usuarios WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $edit_user = $result->fetch_assoc();
        } else {
            $message = "<div class='alert alert-warning'>Usuário para edição não encontrado.</div>";
        }
        $stmt->close();
    } else {
        $message = "<div class='alert alert-danger'>Erro ao preparar declaração de busca: " . $conn->error . "</div>";
    }
}

$users = [];
// ALTERADO: Inclui a nova coluna na listagem de usuários.
$stmt = $conn->prepare("SELECT id, nome, email, ativo, role, email_sender_name_type FROM usuarios ORDER BY nome ASC");
if ($stmt) {
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $message .= "<div class='alert alert-danger'>Erro ao buscar usuários: " . $conn->error . "</div>";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="tinymce/js/tinymce/tinymce.min.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 1000px; margin-top: 50px; margin-bottom: 50px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Gerenciamento de Usuários</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h5><?php echo $edit_user ? 'Editar Usuário' : 'Adicionar Novo Usuário'; ?></h5>
            </div>
            <div class="card-body">
                <form action="manage_users.php" method="POST">
                    <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($edit_user['id']); ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nome" class="form-label">Nome Completo</label>
                            <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($edit_user['nome'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">E-mail de Acesso</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="senha" class="form-label"><?php echo $edit_user ? 'Nova Senha' : 'Senha'; ?></label>
                            <input type="password" class="form-control" id="senha" name="senha" placeholder="<?php echo $edit_user ? 'Deixe em branco para não alterar' : ''; ?>" <?php echo $edit_user ? '' : 'required'; ?>>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="role" class="form-label">Função no Sistema</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="atendente" <?php echo (($edit_user['role'] ?? 'atendente') === 'atendente') ? 'selected' : ''; ?>>Atendente</option>
                                <option value="admin" <?php echo (($edit_user['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="email_sender_name_type" class="form-label">Nome de Exibição no E-mail</label>
                            <select class="form-select" id="email_sender_name_type" name="email_sender_name_type" required>
                                <option value="real_name" <?php echo (($edit_user['email_sender_name_type'] ?? 'real_name') === 'real_name') ? 'selected' : ''; ?>>Usar Nome Real do Usuário</option>
                                <option value="global_name" <?php echo (($edit_user['email_sender_name_type'] ?? '') === 'global_name') ? 'selected' : ''; ?>>Usar Nome Geral do Suporte</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="signature_html" class="form-label">Assinatura de E-mail</label>
                        <textarea name="signature_html" id="signature_html" class="form-control" rows="5"><?php echo htmlspecialchars($edit_user['signature_html'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="ativa" name="ativa" <?php echo ($edit_user['ativo'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ativa">Usuário Ativo</label>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> <?php echo $edit_user ? 'Salvar Alterações' : 'Adicionar Usuário'; ?></button>
                    <?php if ($edit_user): ?>
                        <a href="manage_users.php" class="btn btn-secondary ms-2"><i class="bi bi-x-circle me-1"></i> Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5>Lista de Usuários</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>E-mail</th>
                                <th>Função</th>
                                <th>Nome no E-mail</th> <th>Ativo</th>
                                <th class="text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                    <td>
                                        <?php if ($user['email_sender_name_type'] === 'global_name'): ?>
                                            <span class="badge bg-secondary">Geral do Suporte</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">Nome Real</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $user['ativo'] ? '<span class="badge bg-success">Sim</span>' : '<span class="badge bg-danger">Não</span>'; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="manage_users.php?edit_id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                        <form action="manage_users.php" method="POST" class="d-inline" onsubmit="return confirm('Tem certeza?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Excluir" <?php echo ($user['id'] === $_SESSION['usuario_id']) ? 'disabled' : ''; ?>>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
   <script>
    tinymce.init({
        // ATENÇÃO: Verifique se o nome do campo é 'assinatura'.
        // Se for diferente, ajuste o seletor abaixo.
        selector: 'textarea#signature_html',
          promotion: false,
        language: 'pt_BR',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table | align lineheight | numlist bullist indent outdent | emoticons charmap | removeformat',
        
        // Remove a borda "Powered by Tiny"
        branding: false, 
        
        // Define uma altura padrão para o editor
        height: 250 
    });
</script>
</body>
</html>