<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adiciona o guardião no topo da página para garantir que o usuário está logado
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// --- FUNÇÃO AUXILIAR ---
function parse_emails($email_string) {
    $emails = explode(',', $email_string);
    $valid_emails = [];
    foreach ($emails as $email) {
        $trimmed_email = trim($email);
        if (filter_var($trimmed_email, FILTER_VALIDATE_EMAIL)) {
            $valid_emails[] = $trimmed_email;
        }
    }
    return $valid_emails;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: compose.php');
    exit;
}

// --- COLETA DOS DADOS DO FORMULÁRIO ---
$to_emails_str = $_POST['to_emails'] ?? '';
$cc_emails_str = $_POST['cc_emails'] ?? '';
$bcc_emails_str = $_POST['bcc_emails'] ?? '';
$subject = trim($_POST['subject']);
$corpo_mensagem_original = $_POST['corpo_mensagem'];
$current_user_id = $_SESSION['usuario_id'];
$categoria_id = (int)$_POST['categoria_id']; // NOVO: Coleta a categoria_id

$to_emails = parse_emails($to_emails_str);
$cc_emails = parse_emails($cc_emails_str);
$bcc_emails = parse_emails($bcc_emails_str);

if (empty($to_emails) || empty($subject) || empty($categoria_id)) { // NOVO: Categoria é obrigatória
    die("Erro: Destinatário, Assunto e Categoria são obrigatórios.");
}

$mail = new PHPMailer(true);

try {
    // --- CONFIGURAÇÃO DO PHPMailer ---
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    // Remetente e Destinatários
    $mail->setFrom(SMTP_USER, $_SESSION['usuario_nome']);
    $mail->addReplyTo(SMTP_USER, 'Suporte');
    foreach ($to_emails as $email) { $mail->addAddress($email); }
    // Adiciona os e-mails em cópia
    foreach ($cc_emails as $email) { $mail->addCC($email); }
    foreach ($bcc_emails as $email) { $mail->addBCC($email); }
    
    // Gera um Message-ID único para o novo e-mail
    $new_message_id = '<' . bin2hex(random_bytes(16)) . '@' . explode('@', SMTP_USER)[1] . '>';
    $mail->MessageID = $new_message_id;

    // Anexos
    if (isset($_FILES['anexos']) && !empty($_FILES['anexos']['name'][0])) {
        foreach ($_FILES['anexos']['name'] as $key => $name) {
            if ($_FILES['anexos']['error'][$key] == 0) {
                $mail->addAttachment($_FILES['anexos']['tmp_name'][$key], $name);
            }
        }
    }

    // Processa Imagens Embutidas
    $corpo_para_email = $corpo_mensagem_original;
    preg_match_all('/<img src="data:image\/(jpeg|png|gif);base64,([^"]+)"/i', $corpo_para_email, $matches, PREG_SET_ORDER);
    foreach ($matches as $i => $match) {
        $image_data = base64_decode($match[2]);
        $image_type = $match[1];
        $cid = 'image_' . uniqid();
        $mail->addStringEmbeddedImage($image_data, $cid, "image.{$image_type}");
        $corpo_para_email = str_replace($match[0], '<img src="cid:' . $cid . '"', $corpo_para_email);
    }

    // Conteúdo do E-mail
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $corpo_para_email;
    $mail->AltBody = strip_tags($corpo_para_email);

    // Envia o e-mail
    $mail->send();

    // --- SALVA O NOVO CHAMADO E A MENSAGEM NO BANCO DE DADOS ---
    $conn->begin_transaction();
    try {
        // 1. Cria o novo chamado - NOVO: Inclui categoria_id
        $data_envio = date("Y-m-d H:i:s");
        $stmt_chamado = $conn->prepare("INSERT INTO chamados (usuario_id, assunto, email_cliente, data_criacao, ultimo_update, status, prioridade, requer_atencao, categoria_id) VALUES (?, ?, ?, ?, ?, 'Pendente', 'Media', 0, ?)"); //
        $stmt_chamado->bind_param("issssi", $current_user_id, $subject, $to_emails_str, $data_envio, $data_envio, $categoria_id); //
        $stmt_chamado->execute();
        $chamado_id = $conn->insert_id;
        $stmt_chamado->close();
        
        // 2. Salva a mensagem enviada
        $corpo_texto = strip_tags($corpo_mensagem_original);
        $remetente_nome = $_SESSION['usuario_nome'];
        $remetente_email = SMTP_USER;
        $tipo_msg = 'enviado';
        $in_reply_to_header = null;
        $references_header = null;
        
        // Prepara as strings de CC e BCC para o banco
        $cc_emails_db = !empty($cc_emails) ? implode(', ', $cc_emails) : null;
        $bcc_emails_db = !empty($bcc_emails) ? implode(', ', $bcc_emails) : null;

        $stmt_msg = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, in_reply_to_header, references_header, remetente_nome, remetente_email, destinatario_email, cc_emails, bcc_emails, corpo_html, corpo_texto, data_envio, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_msg->bind_param("issssssssssss", $chamado_id, $new_message_id, $in_reply_to_header, $references_header, $remetente_nome, $remetente_email, $to_emails_str, $cc_emails_db, $bcc_emails_db, $corpo_mensagem_original, $corpo_texto, $data_envio, $tipo_msg);
        
        $stmt_msg->execute();
        $mensagem_id = $conn->insert_id;
        $stmt_msg->close();

        // 3. Salva os anexos (se houver)
        if (isset($_FILES['anexos']) && !empty($_FILES['anexos']['name'][0])) {
            foreach ($_FILES['anexos']['name'] as $key => $name) {
                if ($_FILES['anexos']['error'][$key] == 0) {
                    $conteudo = file_get_contents($_FILES['anexos']['tmp_name'][$key]);
                    $tipo_mime = $_FILES['anexos']['type'][$key];
                    $tamanho = $_FILES['anexos']['size'][$key];
                    $stmt_anexo = $conn->prepare("INSERT INTO anexos (mensagem_id, nome_arquivo, tipo_mime, tamanho_bytes, conteudo) VALUES (?, ?, ?, ?, ?)");
                    $null = NULL;
                    $stmt_anexo->bind_param("issib", $mensagem_id, $name, $tipo_mime, $tamanho, $null);
                    $stmt_anexo->send_long_data(4, $conteudo);
                    $stmt_anexo->execute();
                    $stmt_anexo->close();
                }
            }
        }
        
        $conn->commit();

        // 4. Salva o e-mail na pasta de enviados do Gmail
        $path = IMAP_SERVER . IMAP_SENT_MAILBOX;
        $imapStream = imap_open($path, SMTP_USER, SMTP_PASS);
        imap_append($imapStream, $path, $mail->getSentMIMEMessage());
        imap_close($imapStream);

        // Redireciona para a tela do chamado recém-criado
        header('Location: view_ticket.php?id=' . $chamado_id);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        die("Erro ao salvar os dados no banco: " . $e->getMessage());
    }

} catch (Exception $e) {
    echo "A mensagem não pôde ser enviada. Erro do Mailer: {$mail->ErrorInfo}";
}