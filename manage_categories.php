<?php
require_once 'auth_check.php'; // Garante que o usuário está logado
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

$message = ''; // Para exibir mensagens de sucesso ou erro

// --- Lógica para Adicionar/Editar Categoria ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $nome = trim($_POST['nome']);
        $ativa = isset($_POST['ativa']) ? 1 : 0;

        if (empty($nome)) {
            $message = "<div class='alert alert-danger'>O nome da categoria não pode ser vazio!</div>";
        } else {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO categorias_chamado (nome, ativa) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param("si", $nome, $ativa);
                    if ($stmt->execute()) {
                        $message = "<div class='alert alert-success'>Categoria '<strong>" . htmlspecialchars($nome) . "</strong>' adicionada com sucesso!</div>";
                    } else {
                        $message = "<div class='alert alert-danger'>Erro ao adicionar categoria: " . $stmt->error . "</div>";
                    }
                    $stmt->close();
                } else {
                    $message = "<div class='alert alert-danger'>Erro ao preparar declaração de adição: " . $conn->error . "</div>";
                }
            } elseif ($action === 'edit') {
                $category_id = (int)$_POST['category_id'];
                $stmt = $conn->prepare("UPDATE categorias_chamado SET nome = ?, ativa = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sii", $nome, $ativa, $category_id);
                    if ($stmt->execute()) {
                        $message = "<div class='alert alert-success'>Categoria '<strong>" . htmlspecialchars($nome) . "</strong>' atualizada com sucesso!</div>";
                    } else {
                        $message = "<div class='alert alert-danger'>Erro ao atualizar categoria: " . $stmt->error . "</div>";
                    }
                    $stmt->close();
                } else {
                    $message = "<div class='alert alert-danger'>Erro ao preparar declaração de edição: " . $conn->error . "</div>";
                }
            } elseif ($action === 'delete') {
                $category_id = (int)$_POST['category_id'];
                $stmt = $conn->prepare("DELETE FROM categorias_chamado WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $category_id);
                    if ($stmt->execute()) {
                        $message = "<div class='alert alert-success'>Categoria excluída com sucesso!</div>";
                    } else {
                        $message = "<div class='alert alert-danger'>Erro ao excluir categoria: " . $stmt->error . "</div>";
                    }
                    $stmt->close();
                } else {
                    $message = "<div class='alert alert-danger'>Erro ao preparar declaração de exclusão: " . $conn->error . "</div>";
                }
            }
        }
    }
}

// --- Lógica para pré-preencher formulário de edição ---
$edit_category = null;
if (isset($_GET['edit_id']) && is_numeric($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $conn->prepare("SELECT id, nome, ativa FROM categorias_chamado WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $edit_category = $result->fetch_assoc();
        } else {
            $message = "<div class='alert alert-warning'>Categoria para edição não encontrada.</div>";
        }
        $stmt->close();
    } else {
        $message = "<div class='alert alert-danger'>Erro ao preparar declaração de busca para edição: " . $conn->error . "</div>";
    }
}

// --- Busca todas as categorias para exibir na tabela ---
$categories = [];
$stmt = $conn->prepare("SELECT id, nome, ativa FROM categorias_chamado ORDER BY nome ASC");
if ($stmt) {
    $stmt->execute();
    $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $message .= "<div class='alert alert-danger'>Erro ao buscar categorias: " . $conn->error . "</div>";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Categorias - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .table-actions { width: 150px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Configuração de Categorias</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Chamados</a>
        </div>

        <?php echo $message; // Exibe mensagens de sucesso/erro ?>

        <div class="card mb-4">
            <div class="card-header">
                <?php echo $edit_category ? 'Editar Categoria' : 'Adicionar Nova Categoria'; ?>
            </div>
            <div class="card-body">
                <form action="manage_categories.php" method="POST">
                    <input type="hidden" name="action" value="<?php echo $edit_category ? 'edit' : 'add'; ?>">
                    <?php if ($edit_category): ?>
                        <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($edit_category['id']); ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome da Categoria:</label>
                        <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($edit_category['nome'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="ativa" name="ativa" <?php echo ($edit_category['ativa'] ?? 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ativa">Ativa</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-<?php echo $edit_category ? 'save' : 'plus-circle'; ?> me-1"></i> 
                        <?php echo $edit_category ? 'Atualizar Categoria' : 'Adicionar Categoria'; ?>
                    </button>
                    <?php if ($edit_category): ?>
                        <a href="manage_categories.php" class="btn btn-warning ms-2"><i class="bi bi-x-circle me-1"></i> Cancelar Edição</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Lista de Categorias
            </div>
            <div class="card-body">
                <table class="table table-hover table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Ativa</th>
                            <th class="text-center table-actions">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">Nenhuma categoria encontrada.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['id']); ?></td>
                                    <td><?php echo htmlspecialchars($category['nome']); ?></td>
                                    <td>
                                        <?php if ($category['ativa']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> Sim</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle-fill"></i> Não</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="manage_categories.php?edit_id=<?php echo htmlspecialchars($category['id']); ?>" class="btn btn-sm btn-info me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                                        <form action="manage_categories.php" method="POST" style="display:inline-block;" onsubmit="return confirm('Tem certeza que deseja excluir a categoria \'<?php echo htmlspecialchars($category['nome']); ?>\'? Isso não excluirá chamados associados, mas eles ficarão sem categoria.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($category['id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>