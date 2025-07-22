<?php
// Adiciona o guardião no topo da página para garantir que o usuário está logado
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// --- COLETA DOS DADOS DO FORMULÁRIO ---
$chamado_id = (int)$_POST['chamado_id'];
$nota_interna = trim($_POST['nota_interna']);
$novo_usuario_id = !empty($_POST['novo_usuario_id']) ? (int)$_POST['novo_usuario_id'] : null;
$novo_status = $_POST['status_nota'] ?? '';
$nova_categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;

// --- DADOS DO USUÁRIO LOGADO ---
$current_user_id = $_SESSION['usuario_id'];
$current_user_name = $_SESSION['usuario_nome'];

// Validação básica
if (empty($chamado_id) || empty($nota_interna)) {
    die("Erro: A nota interna não pode estar vazia.");
}

$conn->begin_transaction();

try {
    // 1. Busca o status atual, a categoria atual E O USUÁRIO ATRIBUÍDO atual do chamado ANTES da atualização para comparação
    $stmt_current_details = $conn->prepare("SELECT status, categoria_id, usuario_id FROM chamados WHERE id = ?");
    $stmt_current_details->bind_param("i", $chamado_id);
    $stmt_current_details->execute();
    $current_details_row = $stmt_current_details->get_result()->fetch_assoc();
    $current_chamado_status = $current_details_row['status'];
    $current_chamado_categoria_id = $current_details_row['categoria_id'];
    $current_chamado_usuario_id = $current_details_row['usuario_id']; // NOVO: Usuário atribuído atualmente
    $stmt_current_details->close();

    // Busca o nome do responsável atual (se existir) para a nota interna de reatribuição
    $current_responsavel_nome = 'Ninguém';
    if ($current_chamado_usuario_id) {
        $stmt_resp = $conn->prepare("SELECT nome FROM usuarios WHERE id = ?");
        $stmt_resp->bind_param("i", $current_chamado_usuario_id);
        $stmt_resp->execute();
        $result_resp = $stmt_resp->get_result()->fetch_assoc();
        if ($result_resp) {
            $current_responsavel_nome = $result_resp['nome'];
        }
        $stmt_resp->close();
    }

    // Busca o nome da categoria atual (se existir) para a nota interna
    $current_categoria_nome = null;
    if ($current_chamado_categoria_id) {
        $stmt_cat = $conn->prepare("SELECT nome FROM categorias_chamado WHERE id = ?");
        $stmt_cat->bind_param("i", $current_chamado_categoria_id);
        $stmt_cat->execute();
        $result_cat = $stmt_cat->get_result()->fetch_assoc();
        $current_categoria_nome = $result_cat['nome'];
        $stmt_cat->close();
    }

    // Busca o nome da NOVA categoria (se selecionada) para a nota interna
    $new_categoria_nome = null;
    if ($nova_categoria_id) {
        $stmt_cat_new = $conn->prepare("SELECT nome FROM categorias_chamado WHERE id = ?");
        $stmt_cat_new->bind_param("i", $nova_categoria_id);
        $stmt_cat_new->execute();
        $result_cat_new = $stmt_cat_new->get_result()->fetch_assoc();
        $new_categoria_nome = $result_cat_new['nome'];
        $stmt_cat_new->close();

        // ==========================================================
// INÍCIO DA LÓGICA DE REABERTURA
// ==========================================================
$status_final = !empty($novo_status) ? $novo_status : $current_chamado_status; // Começa com o status vindo do formulário ou o status atual

if ($current_chamado_status === 'Fechado') {
    // Se o chamado ESTAVA fechado, qualquer nota ou alteração FORÇA a reabertura.
    $status_final = 'Aberto';
}
// ==========================================================
// FIM DA LÓGICA DE REABERTURA
// ==========================================================
    }


    // 2. Prepara o corpo da nota interna principal, que incluirá o texto digitado e as informações de alteração
    $corpo_nota_final = nl2br(htmlspecialchars($nota_interna)); // Começa com o texto digitado
    $change_details_added = false; // Flag para controlar se uma linha horizontal foi adicionada

    // LÓGICA DE REATRIBUIÇÃO: Adiciona informação de reatribuição ao corpo da nota, SOMENTE SE HOUVE MUDANÇA
    // E só se um novo usuário foi de fato selecionado
    if ($novo_usuario_id !== null && $novo_usuario_id !== $current_chamado_usuario_id) {
        $novo_usuario_nome = 'N/A'; // Default caso o ID não seja encontrado (improvável)
        $stmt_user_new = $conn->prepare("SELECT nome FROM usuarios WHERE id = ?");
        $stmt_user_new->bind_param("i", $novo_usuario_id);
        $stmt_user_new->execute();
        $result_user_new = $stmt_user_new->get_result();
        if ($user_row_new = $result_user_new->fetch_assoc()) {
            $novo_usuario_nome = $user_row_new['nome'];
        }
        $stmt_user_new->close();

        // Adiciona a linha horizontal apenas se for a primeira mudança
        if (!$change_details_added) { $corpo_nota_final .= "<br><br><hr>"; $change_details_added = true; }
        $corpo_nota_final .= "<em>Chamado reatribuído de <strong>" . htmlspecialchars($current_responsavel_nome) . "</strong> para <strong>" . htmlspecialchars($novo_usuario_nome) . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
    }


    // LÓGICA DE ALTERAÇÃO DE STATUS: Adiciona informação de mudança de status ao corpo da nota, SOMENTE SE HOUVE MUDANÇA
    if (!empty($novo_status)) {
        $allowed_status = ['Aberto', 'Pendente', 'Fechado'];
        if (in_array($status_final, $allowed_status) && $status_final !== $current_chamado_status) {
            if (!$change_details_added) { $corpo_nota_final .= "<br><br><hr>"; $change_details_added = true; }
            $corpo_nota_final .= "<em>O status do chamado foi alterado de <strong>" . htmlspecialchars($current_chamado_status) . "</strong> para <strong>" . htmlspecialchars($status_final) . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
        }
    }

    // LÓGICA DE ALTERAÇÃO DE CATEGORIA: Adiciona informação de mudança de categoria ao corpo da nota, SOMENTE SE HOUVE MUDANÇA
    if ($nova_categoria_id !== null && $nova_categoria_id !== $current_chamado_categoria_id) {
        if (!$change_details_added) { $corpo_nota_final .= "<br><br><hr>"; $change_details_added = true; }
        $corpo_nota_final .= "<em>A categoria do chamado foi alterada de <strong>" . htmlspecialchars($current_categoria_nome ?? 'Não Definida') . "</strong> para <strong>" . htmlspecialchars($new_categoria_nome ?? 'Não Definida') . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
    }

    // 3. Insere APENAS UMA nota interna na tabela de mensagens com o corpo combinado
    $data_envio = date("Y-m-d H:i:s");
    $tipo_msg = 'interno';
    $nota_message_id = '<nota.' . time() . '.' . bin2hex(random_bytes(8)) . '@sistema.local>';

    $stmt_nota = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, remetente_nome, corpo_html, data_envio, tipo) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_nota->bind_param("isssss", $chamado_id, $nota_message_id, $current_user_name, $corpo_nota_final, $data_envio, $tipo_msg);
    $stmt_nota->execute();
    $stmt_nota->close();

    // 4. Atualiza o chamado (status, prioridade, usuário, categoria, etc.)
    $update_fields = ["ultimo_update = NOW()"];
    $update_types = "";
    $update_params = [];

    // Lógica para atribuição de usuário: SOMENTE ATUALIZA SE HOUVE MUDANÇA EXPLÍCITA
    if ($novo_usuario_id !== null && $novo_usuario_id !== $current_chamado_usuario_id) {
        $update_fields[] = "usuario_id = ?";
        $update_types .= "i";
        $update_params[] = $novo_usuario_id;
    }
    // NOTA: Se $novo_usuario_id é null ou é igual ao atual, o usuario_id não é alterado.

    // Lógica para atualização de status no chamado: SOMENTE ATUALIZA SE HOUVE MUDANÇA EXPLÍCITA
    if (!empty($novo_status)) {
        $allowed_status = ['Aberto', 'Pendente', 'Fechado'];
        if (in_array($status_final, $allowed_status) && $status_final !== $current_chamado_status) {
            $update_fields[] = "status = ?";
            $update_types .= "s";
           $update_params[] = $status_final;
        }
    }

    // Lógica para atualização de categoria no chamado: SOMENTE ATUALIZA SE HOUVE MUDANÇA EXPLÍCITA
    if ($nova_categoria_id !== null && $nova_categoria_id !== $current_chamado_categoria_id) {
        $update_fields[] = "categoria_id = ?";
        $update_types .= "i";
        $update_params[] = $nova_categoria_id;
    }


    // Prepara e executa o UPDATE na tabela chamados SOMENTE SE HOUVER CAMPOS PARA ATUALIZAR
    if (count($update_fields) > 1 || $update_fields[0] !== "ultimo_update = NOW()") { // Sempre atualiza ultimo_update
        $update_sql = "UPDATE chamados SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt_update = $conn->prepare($update_sql);
        
        // O ID do chamado é sempre o último parâmetro para o WHERE
        $update_params[] = $chamado_id; 
        $stmt_update->bind_param($update_types . "i", ...$update_params);

        $stmt_update->execute();
        $stmt_update->close();
    }
    // Se nenhum campo além de ultimo_update foi alterado, nenhum UPDATE extra é feito, apenas a nota interna.


    // Confirma as alterações no banco
    $conn->commit();

    // Redireciona de volta para a tela do chamado
    header('Location: view_ticket.php?id=' . $chamado_id);
    exit;

} catch (Exception $e) {
    // Em caso de erro, desfaz tudo
    $conn->rollback();
    die("Erro ao salvar a nota interna: " . $e->getMessage());
}