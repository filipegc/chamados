<?php
// Arquivo: includes/ticket_assignment.php

/**
 * Atribui o próximo usuário em um esquema de rodízio.
 *
 * @param mysqli $conn A conexão com o banco de dados.
 * @return int|null O ID do próximo usuário, ou null se não houver usuários ativos.
 */
function assignNextUserInRoundRobin($conn) {
    // Busca o último usuário atribuído
    $last_assigned_user_id = null;
    $stmt_config = $conn->prepare("SELECT config_value FROM sistema_config WHERE config_key = 'last_assigned_user_id'");
    if ($stmt_config && $stmt_config->execute()) {
        $result_config = $stmt_config->get_result();
        if ($row_config = $result_config->fetch_assoc()) {
            $last_assigned_user_id = (int)$row_config['config_value'];
        }
        $stmt_config->close();
    } else {
        error_log("Erro na preparação/execução da query para last_assigned_user_id: " . $conn->error);
    }

    // Busca todos os IDs de usuários ativos, ordenados por ID (para um rodízio consistente)
    $active_user_ids = [];
    $stmt_users = $conn->prepare("SELECT id FROM usuarios WHERE ativo = 1 ORDER BY id ASC");
    if ($stmt_users && $stmt_users->execute()) {
        $result_users = $stmt_users->get_result();
        while ($row_user = $result_users->fetch_assoc()) {
            $active_user_ids[] = (int)$row_user['id'];
        }
        $stmt_users->close();
    } else {
        error_log("Erro na preparação/execução da query para usuários ativos: " . $conn->error);
    }

    if (empty($active_user_ids)) {
        return null; // Não há usuários ativos para atribuir
    }

    $next_user_id = null;
    $num_users = count($active_user_ids);

    // Encontra o índice do último usuário atribuído
    $last_index = -1;
    if ($last_assigned_user_id && in_array($last_assigned_user_id, $active_user_ids)) {
        $last_index = array_search($last_assigned_user_id, $active_user_ids);
    }

    // Calcula o próximo índice no rodízio
    $next_index = ($last_index + 1) % $num_users;
    $next_user_id = $active_user_ids[$next_index];

    // Atualiza o último usuário atribuído na tabela de configuração
    $stmt_update_config = $conn->prepare("INSERT INTO sistema_config (config_key, config_value) VALUES ('last_assigned_user_id', ?) ON DUPLICATE KEY UPDATE config_value = ?");
    if ($stmt_update_config && $stmt_update_config->bind_param("ii", $next_user_id, $next_user_id) && $stmt_update_config->execute()) {
        // Sucesso
    } else {
        error_log("Erro na preparação/execução da query para atualizar last_assigned_user_id: " . ($stmt_update_config ? $stmt_update_config->error : $conn->error));
    }
    if ($stmt_update_config) $stmt_update_config->close();

    return $next_user_id;
}
?>