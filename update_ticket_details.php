<?php
// Garante que o usuário está logado
require_once 'auth_check.php';
// Inclui as configurações do banco de dados. O __DIR__ garante o caminho absoluto.
require_once __DIR__ . '/config/config.php';

// Define o cabeçalho para indicar que a resposta será em JSON
header('Content-Type: application/json');

// Inicializa o array de resposta
$response = ['success' => false, 'message' => ''];

// Garante que a requisição é do tipo POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Filtra e valida os inputs recebidos do formulário
    $chamado_id = filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $prioridade = filter_input(INPUT_POST, 'prioridade', FILTER_SANITIZE_STRING);
    $usuario_id = filter_input(INPUT_POST, 'usuario_id', FILTER_VALIDATE_INT);
    $categoria_id = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT); // NOVO: Coleta a categoria_id

    // Pega o ID do usuário logado
    $current_user_id = $_SESSION['usuario_id'];

    // Verifica se o ID do chamado é válido
    if (!$chamado_id) {
        $response['message'] = 'ID do chamado inválido.';
        echo json_encode($response);
        exit;
    }

    // Validações para os valores de status e prioridade
    $allowed_statuses = ['Aberto', 'Pendente', 'Fechado'];
    $allowed_priorities = ['Baixa', 'Media', 'Alta'];

    if (!in_array($status, $allowed_statuses)) {
        $response['message'] = 'Status inválido.';
        echo json_encode($response);
        exit;
    }
    if (!in_array($prioridade, $allowed_priorities)) {
        $response['message'] = 'Prioridade inválida.';
        echo json_encode($response);
        exit;
    }

    try {
        // Campos a serem atualizados
        $update_fields = ["status = ?", "prioridade = ?", "ultimo_update = NOW()"];
        $update_types = "ss"; // Para status e prioridade
        $update_params = [$status, $prioridade]; // Parâmetros iniciais

        if (!empty($usuario_id)) {
            $update_fields[] = "usuario_id = ?";
            $update_types .= "i";
            $update_params[] = $usuario_id;
        } else {
            $update_fields[] = "usuario_id = NULL";
        }

        // NOVO: Adiciona categoria_id aos campos de atualização
        if ($categoria_id !== null) { // Assume que 0 pode ser um valor válido para "não definido" se não for um FK
            $update_fields[] = "categoria_id = ?";
            $update_types .= "i";
            $update_params[] = $categoria_id;
        } else {
             $update_fields[] = "categoria_id = NULL"; // Se não for fornecido, defina como NULL
        }

        $sql = "UPDATE chamados SET " . implode(", ", $update_fields) . " WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        
        // O ID do chamado é sempre o último parâmetro para bind_param
        $update_params[] = $chamado_id;
        
        // Construção final dos tipos e parâmetros para o bind_param
        // A string de tipos precisa corresponder aos ? na query.
        $stmt->bind_param($update_types . "i", ...$update_params);

        // Executa a query
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Chamado atualizado com sucesso!';
        } else {
            $response['message'] = 'Erro ao executar a atualização: ' . $stmt->error;
        }
        $stmt->close();

    } catch (Exception $e) {
        // Captura e retorna erros de exceção
        $response['message'] = 'Erro no servidor: ' . $e->getMessage();
    }
} else {
    // Se a requisição não for POST, retorna um erro
    $response['message'] = 'Método de requisição inválido.';
}

// Retorna a resposta em JSON
echo json_encode($response);