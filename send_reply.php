<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adiciona o guardião no topo da página
require_once 'auth_check.php';
// Inclui configurações e o autoload do Composer
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// --- COLETA DOS DADOS DO FORMULÁRIO ---
$chamado_id = (int)($_POST['chamado_id'] ?? 0);
$corpo_resposta_original = $_POST['corpo_resposta'] ?? '';
$to_emails_str = $_POST['to_emails'] ?? '';
$cc_emails_str = $_POST['cc_emails'] ?? '';
$bcc_emails_str = $_POST['bcc_emails'] ?? '';
$status = $_POST['status'] ?? '';
$prioridade = $_POST['prioridade'] ?? '';
$categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;

// --- DADOS DO USUÁRIO LOGADO ---
$atendente_id = $_SESSION['usuario_id'];
$current_user_name = $_SESSION['usuario_nome'];

// --- Validação básica ---
if (!$chamado_id || empty($corpo_resposta_original) || empty($to_emails_str)) {
    header('Location: view_ticket.php?id=' . $chamado_id . '&error=' . urlencode('Campos obrigatórios (Para, Mensagem) não foram preenchidos.'));
    exit;
}

try {
    // --- 1. BUSCA DE DADOS ---

    // ALTERADO: Busca também o 'usuario_id' atual do chamado
    $stmt = $conn->prepare("SELECT assunto, status, categoria_id, usuario_id FROM chamados WHERE id = ?");
    $current_chamado_usuario_id = $chamado_data['usuario_id']; // ID do responsável atual
    $stmt->bind_param("i", $chamado_id);
    $stmt->execute();
    $chamado_data = $stmt->get_result()->fetch_assoc();
    $current_chamado_status = $chamado_data['status'];
    $current_chamado_categoria_id = $chamado_data['categoria_id'];
    $current_chamado_usuario_id = $chamado_data['usuario_id']; // ID do responsável atual
    $chamado_assunto = $chamado_data['assunto'];
    $stmt->close();
    if (!$chamado_data) { throw new Exception("Chamado não encontrado."); }

    if ($current_chamado_status === 'Aberto') {
        $status = 'Pendente';
    }

    $stmt_user = $conn->prepare("SELECT nome, email_sender_name_type, signature_html FROM usuarios WHERE id = ?");
    $stmt_user->bind_param("i", $atendente_id);
    $stmt_user->execute();
    $atendente_info = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();
    if (!$atendente_info) { throw new Exception("Atendente não encontrado."); }

    $stmt_last_msg = $conn->prepare("SELECT message_id_header, references_header, corpo_html, data_envio, remetente_nome FROM mensagens WHERE chamado_id = ? AND tipo IN ('recebido', 'automatico') ORDER BY data_envio DESC LIMIT 1");
    $stmt_last_msg->bind_param("i", $chamado_id);
    $stmt_last_msg->execute();
    $last_client_msg = $stmt_last_msg->get_result()->fetch_assoc();
    $stmt_last_msg->close();

    // --- 2. PREPARAÇÃO DO E-MAIL ---
    
    // Determina o nome do remetente com base na configuração do usuário
    $nome_remetente = '';
    if ($atendente_info['email_sender_name_type'] === 'global_name') {
        $stmt_config = $conn->prepare("SELECT config_value FROM sistema_config WHERE config_key = 'support_sender_name'");
        $stmt_config->execute();
        $config = $stmt_config->get_result()->fetch_assoc();
        $stmt_config->close();
        $nome_remetente = $config['config_value'] ?? 'Equipe de Suporte';
    } else {
        $nome_remetente = $atendente_info['nome'];
    }
    
    // Monta o corpo completo do e-mail com histórico e assinatura
    $corpo_para_email = $corpo_resposta_original;
    if (!empty($atendente_info['signature_html'])) {
        $corpo_para_email .= '<br><br>' . $atendente_info['signature_html'];
    }
    if ($last_client_msg && !empty($last_client_msg['corpo_html'])) {
        $data_anterior = date('d/m/Y H:i', strtotime($last_client_msg['data_envio']));
        $remetente_anterior = htmlspecialchars($last_client_msg['remetente_nome']);
        $corpo_para_email .= '<br><br><hr style="border:none; border-top:1px solid #ccc;"><blockquote>Em ' . $data_anterior . ', ' . $remetente_anterior . ' escreveu:<br>' . $last_client_msg['corpo_html'] . '</blockquote>';
    }

    // --- 3. ENVIO DO E-MAIL ---
    $mail = new PHPMailer(true);
    
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_USER, $nome_remetente);
    $mail->addReplyTo(SMTP_USER);

    $to_emails = array_filter(array_map('trim', explode(',', $to_emails_str)));
    foreach ($to_emails as $email) { $mail->addAddress($email); }

    $cc_emails = array_filter(array_map('trim', explode(',', $cc_emails_str)));
    foreach ($cc_emails as $email) { $mail->addCC($email); }

    $bcc_emails = array_filter(array_map('trim', explode(',', $bcc_emails_str)));
    foreach ($bcc_emails as $email) { $mail->addBCC($email); }

    $mail->Subject = 'Re: ' . $chamado_assunto;
    
    $new_message_id = '<reply.' . bin2hex(random_bytes(16)) . '@' . explode('@', SMTP_USER)[1] . '>';
    $mail->MessageID = $new_message_id;

    $in_reply_to_header = $last_client_msg['message_id_header'] ?? null;
    $references_header = $last_client_msg['references_header'] ? $last_client_msg['references_header'] . ' ' . $in_reply_to_header : $in_reply_to_header;
    if ($in_reply_to_header) $mail->addCustomHeader('In-Reply-To', $in_reply_to_header);
    if ($references_header) $mail->addCustomHeader('References', $references_header);

    // LÓGICA ORIGINAL PARA ANEXOS E IMAGENS (PRESERVADA)
    $anexos_para_db = [];
    if (isset($_FILES['anexos']) && !empty($_FILES['anexos']['name'][0])) {
        foreach ($_FILES['anexos']['name'] as $key => $name) {
            if ($_FILES['anexos']['error'][$key] == 0) {
                $file_tmp_name = $_FILES['anexos']['tmp_name'][$key];
                $mail->addAttachment($file_tmp_name, $name);
                $anexos_para_db[] = [ 'nome_arquivo' => $name, 'tipo_mime' => mime_content_type($file_tmp_name), 'tamanho_bytes' => $_FILES['anexos']['size'][$key], 'conteudo' => file_get_contents($file_tmp_name) ];
            }
        }
    }

    $imagens_embutidas_para_db = [];
    preg_match_all('/<img src="data:image\/(jpeg|png|gif);base64,([^"]+)"/i', $corpo_para_email, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $image_data = base64_decode($match[2]);
        $cid = 'image_' . uniqid();
        $mail->addStringEmbeddedImage($image_data, $cid, "image.{$match[1]}");
        $corpo_para_email = str_replace($match[0], '<img src="cid:' . $cid . '"', $corpo_para_email);
        $imagens_embutidas_para_db[] = [ 'content_id' => $cid, 'tipo_mime' => "image/{$match[1]}", 'conteudo' => $image_data ];
    }

    $mail->isHTML(true);
    $mail->Body = $corpo_para_email;
    $mail->AltBody = strip_tags($corpo_para_email);
    
    $mail->send();

    // --- 4. SALVA TUDO NO BANCO DE DADOS ---
    $conn->begin_transaction();

    // Salva a mensagem principal enviada
    $data_envio = date("Y-m-d H:i:s");
    $cc_db = !empty($cc_emails) ? implode(', ', $cc_emails) : null;
    $bcc_db = !empty($bcc_emails) ? implode(', ', $bcc_emails) : null;
    
    $stmt_save = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, in_reply_to_header, references_header, remetente_nome, remetente_email, destinatario_email, cc_emails, bcc_emails, corpo_html, corpo_texto, data_envio, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'enviado')");
    $remetente_email_var = SMTP_USER;
    $stmt_save->bind_param("isssssssssss", $chamado_id, $new_message_id, $in_reply_to_header, $references_header, $nome_remetente, $remetente_email_var, $to_emails_str, $cc_db, $bcc_db, $corpo_resposta_original, strip_tags($corpo_resposta_original), $data_envio);
    $stmt_save->execute();
    $mensagem_id = $stmt_save->insert_id;
    $stmt_save->close();
    
    // LÓGICA ORIGINAL PARA SALVAR ANEXOS E IMAGENS (PRESERVADA)
    if (!empty($anexos_para_db)) {
        $stmt_anexos = $conn->prepare("INSERT INTO anexos (mensagem_id, nome_arquivo, tipo_mime, tamanho_bytes, conteudo) VALUES (?, ?, ?, ?, ?)");
        foreach ($anexos_para_db as $anexo) {
            $null = NULL;
            $stmt_anexos->bind_param("issib", $mensagem_id, $anexo['nome_arquivo'], $anexo['tipo_mime'], $anexo['tamanho_bytes'], $null);
            $stmt_anexos->send_long_data(4, $anexo['conteudo']);
            $stmt_anexos->execute();
        }
        $stmt_anexos->close();
    }
    if (!empty($imagens_embutidas_para_db)) {
        $stmt_imagens = $conn->prepare("INSERT INTO imagens_embutidas (mensagem_id, content_id, tipo_mime, conteudo) VALUES (?, ?, ?, ?)");
        foreach ($imagens_embutidas_para_db as $imagem) {
            $null = NULL;
            $stmt_imagens->bind_param("issb", $mensagem_id, $imagem['content_id'], $imagem['tipo_mime'], $null);
            $stmt_imagens->send_long_data(3, $imagem['conteudo']);
            $stmt_imagens->execute();
        }
        $stmt_imagens->close();
    }
    
    // LÓGICA PARA CRIAR NOTAS INTERNAS AUTOMÁTICAS
    if ($current_chamado_usuario_id != $atendente_id) {
        $nome_responsavel_anterior = 'Ninguém';
        if ($current_chamado_usuario_id) {
            $stmt_old_user = $conn->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmt_old_user->bind_param("i", $current_chamado_usuario_id);
            $stmt_old_user->execute();
            if ($old_user_data = $stmt_old_user->get_result()->fetch_assoc()) {
                $nome_responsavel_anterior = $old_user_data['nome'];
            }
            $stmt_old_user->close();
        }

        $transfer_note_body = "<em>Chamado reatribuído de <strong>" . htmlspecialchars($nome_responsavel_anterior) . "</strong> para <strong>" . htmlspecialchars($atendente_info['nome']) . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
        $transfer_note_message_id = '<note.assign.' . time() . '.' . bin2hex(random_bytes(8)) . '@sistema.local>';
        
        $stmt_transfer_note = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, remetente_nome, corpo_html, data_envio, tipo) VALUES (?, ?, ?, ?, NOW(), 'interno')");
        $stmt_transfer_note->bind_param("isss", $chamado_id, $transfer_note_message_id, $current_user_name, $transfer_note_body);
        $stmt_transfer_note->execute();
        $stmt_transfer_note->close();
    }
    
    // Cria nota interna se o status mudou
    if ($status !== $current_chamado_status) {
        $status_note_body = "<em>O status do chamado foi alterado de <strong>" . htmlspecialchars($current_chamado_status) . "</strong> para <strong>" . htmlspecialchars($status) . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
        $status_note_message_id = '<note.status.' . time() . '.' . bin2hex(random_bytes(8)) . '@sistema.local>';
        $stmt_status_note = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, remetente_nome, corpo_html, data_envio, tipo) VALUES (?, ?, ?, ?, NOW(), 'interno')");
        $stmt_status_note->bind_param("isss", $chamado_id, $status_note_message_id, $current_user_name, $status_note_body);
        $stmt_status_note->execute();
        $stmt_status_note->close();
    }

    // Cria nota interna se a categoria mudou
    if ($categoria_id !== null && $categoria_id !== $current_chamado_categoria_id) {
        $current_categoria_nome_query = $conn->query("SELECT nome FROM categorias_chamado WHERE id = " . (int)$current_chamado_categoria_id)->fetch_assoc();
        $new_categoria_nome_query = $conn->query("SELECT nome FROM categorias_chamado WHERE id = " . (int)$categoria_id)->fetch_assoc();
        $current_categoria_nome = $current_categoria_nome_query['nome'] ?? 'Não Definida';
        $new_categoria_nome = $new_categoria_nome_query['nome'] ?? 'Não Definida';

        $category_note_body = "<em>A categoria do chamado foi alterada de <strong>" . htmlspecialchars($current_categoria_nome) . "</strong> para <strong>" . htmlspecialchars($new_categoria_nome) . "</strong> por <strong>" . htmlspecialchars($current_user_name) . "</strong>.</em>";
        $category_note_message_id = '<note.category.' . time() . '.' . bin2hex(random_bytes(8)) . '@sistema.local>';
        
        $stmt_category_note = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, remetente_nome, corpo_html, data_envio, tipo) VALUES (?, ?, ?, ?, NOW(), 'interno')");
        $stmt_category_note->bind_param("isss", $chamado_id, $category_note_message_id, $current_user_name, $category_note_body);
        $stmt_category_note->execute();
        $stmt_category_note->close();
    }

    // Atualiza o chamado
    $stmt_update_chamado = $conn->prepare("UPDATE chamados SET status = ?, prioridade = ?, usuario_id = ?, categoria_id = ?, requer_atencao = 0, ultimo_update = NOW() WHERE id = ?");
    $stmt_update_chamado->bind_param("ssiii", $status, $prioridade, $atendente_id, $categoria_id, $chamado_id);
    $stmt_update_chamado->execute();
    $stmt_update_chamado->close();

    $conn->commit();

    // Salva e-mail na pasta de enviados do IMAP
    $path = IMAP_SERVER . IMAP_SENT_MAILBOX;
    $imapStream = @imap_open($path, SMTP_USER, SMTP_PASS);
    if ($imapStream) {
        imap_append($imapStream, $path, $mail->getSentMIMEMessage());
        imap_close($imapStream);
    } else {
        error_log("Erro IMAP: Não foi possível salvar em 'Enviados'. " . imap_last_error());
    }

    // --- 5. REDIRECIONA COM SUCESSO ---
    header('Location: view_ticket.php?id=' . $chamado_id . '&success=Resposta enviada com sucesso!');
    exit;

} catch (Exception $e) {
    if ($conn->in_transaction) { $conn->rollback(); }
    header('Location: view_ticket.php?id=' . $chamado_id . '&error=' . urlencode('Erro: ' . $e->getMessage()));
    exit;
}
?>