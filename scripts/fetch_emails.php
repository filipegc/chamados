<?php
// ATENÇÃO: As linhas abaixo são para DEBUG. Remova-as em ambiente de produção.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(300); // 5 minutos

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ticket_assignment.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Funções Auxiliares ---
function get_normalized_subject($subject) {
    $prefixes = ['Re:', 'Fwd:', 'Enc:', 'RE:', 'FWD:', 'ENC:'];
    $normalized_subject = trim(str_ireplace($prefixes, '', $subject));
    $normalized_subject = str_replace('_', ' ', $normalized_subject);
    return $normalized_subject;
}

function parse_parts($inbox, $email_uid, $parts, &$corpo_html, &$corpo_texto, &$anexos, &$imagens_embutidas, $part_number = null) {
    foreach ($parts as $i => $subpart) {
        $current_part_number = ($part_number ? $part_number . '.' : '') . ($i + 1);
        $disposition = (isset($subpart->disposition) ? strtolower($subpart->disposition) : null);
        if ($disposition === 'attachment') {
            $params = get_params(isset($subpart->dparameters) ? $subpart->dparameters : []);
            $filename = isset($params['filename']) ? mb_decode_mimeheader($params['filename']) : 'anexo_sem_nome';
            $anexos[] = ['nome' => $filename, 'tipo' => $subpart->subtype, 'tamanho' => $subpart->bytes, 'conteudo' => get_part($inbox, $email_uid, $current_part_number, $subpart->encoding)];
        } elseif (isset($subpart->id)) {
            $cid = trim($subpart->id, '<>');
            $imagens_embutidas[] = ['cid' => $cid, 'tipo' => $subpart->subtype, 'conteudo' => get_part($inbox, $email_uid, $current_part_number, $subpart->encoding)];
        } elseif (strtolower($subpart->subtype) === 'html' && !$corpo_html) {
            $corpo_html = get_part($inbox, $email_uid, $current_part_number, $subpart->encoding);
            if (isset($subpart->parameters[0]) && strtolower($subpart->parameters[0]->attribute) == 'charset') {
                $corpo_html = convert_to_utf8($corpo_html, $subpart->parameters[0]->value);
            }
        } elseif (strtolower($subpart->subtype) === 'plain' && !$corpo_texto) {
            $corpo_texto = get_part($inbox, $email_uid, $current_part_number, $subpart->encoding);
             if (isset($subpart->parameters[0]) && strtolower($subpart->parameters[0]->attribute) == 'charset') {
                $corpo_texto = convert_to_utf8($corpo_texto, $subpart->parameters[0]->value);
            }
        }
        if (isset($subpart->parts)) {
            parse_parts($inbox, $email_uid, $subpart->parts, $corpo_html, $corpo_texto, $anexos, $imagens_embutidas, $current_part_number);
        }
    }
}

function get_part($inbox, $email_uid, $part_number, $encoding) {
    $data = imap_fetchbody($inbox, $email_uid, $part_number, FT_UID);
    switch ($encoding) {
        case 3: return base64_decode($data);
        case 4: return quoted_printable_decode($data);
        default: return $data;
    }
}

function get_params($params_array) {
    $params = [];
    foreach ($params_array as $param) {
        $params[strtolower($param->attribute)] = $param->value;
    }
    return $params;
}

function convert_to_utf8($string, $charset) {
    if (strtoupper($charset) != 'UTF-8' && !empty($charset)) {
        return mb_convert_encoding($string, 'UTF-8', $charset);
    }
    return $string;
}

// --- Função Principal de Processamento de Mailbox ---
function process_mailbox($conn, $mailbox_path, $message_type, $search_criteria) {
    echo "\n--- Processando Mailbox: $mailbox_path com critério: $search_criteria ---\n";
    $inbox = imap_open($mailbox_path, IMAP_USER, IMAP_PASS) or die('Não foi possível conectar à mailbox: ' . imap_last_error());
    $emails = imap_search($inbox, $search_criteria, SE_UID);

    $newly_created_tickets = [];

    if ($emails) {
        echo "Encontrados " . count($emails) . " e-mails para verificar.\n";
        rsort($emails);

        $default_category_id = null;
        $stmt_default_cat = $conn->prepare("SELECT id FROM categorias_chamado WHERE nome = 'Geral' LIMIT 1");
        if ($stmt_default_cat && $stmt_default_cat->execute()) {
            $result_default_cat = $stmt_default_cat->get_result();
            if ($row_default_cat = $result_default_cat->fetch_assoc()) {
                $default_category_id = $row_default_cat['id'];
            }
            $stmt_default_cat->close();
        } else {
            error_log("Erro ao buscar categoria padrão: " . $conn->error);
        }

        foreach ($emails as $email_uid) {
            $header_info = imap_headerinfo($inbox, imap_msgno($inbox, $email_uid));
            $structure = imap_fetchstructure($inbox, $email_uid, FT_UID);
            
            $message_id = !empty($header_info->message_id) ? $header_info->message_id : null;
            if ($message_id === null) {
                $message_id = '<' . bin2hex(random_bytes(16)) . '@' . explode('@', IMAP_USER)[0] . '.local>';
            }

            $stmt_check = $conn->prepare("SELECT id FROM mensagens WHERE message_id_header = ?");
            if (!$stmt_check) { error_log("Erro na preparação stmt_check: " . $conn->error); continue; }
            $stmt_check->bind_param("s", $message_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                imap_setflag_full($inbox, $email_uid, "\\Seen", ST_UID);
                continue;
            }
            $stmt_check->close();
            
            if ($message_type === 'enviado') {
                $decoded_subject_for_check = isset($header_info->subject) ? mb_decode_mimeheader($header_info->subject) : '';
                $from_email_for_check = (isset($header_info->from[0]->mailbox) ? $header_info->from[0]->mailbox . '@' . $header_info->from[0]->host : '');
                if (strpos($decoded_subject_for_check, 'Confirmação: Seu chamado foi criado') !== false && strtolower($from_email_for_check) === strtolower(SMTP_USER)) {
                    imap_setflag_full($inbox, $email_uid, "\\Seen", ST_UID);
                    continue;
                }
            }
            
            $remetente_nome = ''; $remetente_email = ''; $destinatario_email = ''; 
            $cc_emails_str = null; $bcc_emails_str = null; 
            $email_cliente = '';

            if ($message_type === 'recebido') {
                $from_info = $header_info->from[0];
                $remetente_nome = isset($from_info->personal) ? mb_decode_mimeheader($from_info->personal) : $from_info->mailbox;
                $remetente_email = $from_info->mailbox . "@" . $from_info->host;
                $email_cliente = $remetente_email;
            } else { 
                $remetente_nome = 'Suporte'; 
                $remetente_email = IMAP_USER;
            }

            $destinatarios_array = [];
            if (isset($header_info->to)) { foreach ($header_info->to as $to) { $destinatarios_array[] = $to->mailbox . "@" . $to->host; } }
            $destinatario_email = implode(', ', $destinatarios_array);
            
            $cc_array = [];
            if (isset($header_info->cc)) { foreach ($header_info->cc as $cc) { $cc_array[] = $cc->mailbox . "@" . $cc->host; } }
            $cc_emails_str = implode(', ', $cc_array);

            $bcc_array = [];
            if (isset($header_info->bcc)) { foreach ($bcc_array as $bcc) { $bcc_array[] = $bcc->mailbox . "@" . $bcc->host; } }
            $bcc_emails_str = implode(', ', $bcc_array);

            if ($message_type === 'enviado') { $email_cliente = $destinatario_email; } 

            $assunto_original = isset($header_info->subject) ? mb_decode_mimeheader($header_info->subject) : '(Sem Assunto)';
            $data_envio = date("Y-m-d H:i:s", strtotime($header_info->date));
            $in_reply_to = $header_info->in_reply_to ?? null;
            $references = $header_info->references ?? null;
            $corpo_html = null; $corpo_texto = null; $anexos = []; $imagens_embutidas = [];
            
            if (isset($structure->parts)) {
                parse_parts($inbox, $email_uid, $structure->parts, $corpo_html, $corpo_texto, $anexos, $imagens_embutidas);
            } else {
                if (imap_body($inbox, $email_uid, FT_UID)) {
                    $corpo_texto = imap_fetchbody($inbox, $email_uid, "1", FT_UID);
                    if (isset($structure->parameters[0]) && strtolower($structure->parameters[0]->attribute) == 'charset') {
                        $corpo_texto = convert_to_utf8($corpo_texto, $structure->parameters[0]->value);
                    }
                }
            }

            $conn->begin_transaction();
            try {
                $chamado_id = null;
                $current_chamado_status = null; 
                
                if ($in_reply_to || $references) {
                    $search_ids = [];
                    if ($in_reply_to) { $search_ids[] = $in_reply_to; }
                    if ($references) { $search_ids = array_merge($search_ids, explode(' ', $references)); }
                    if (!empty($search_ids)) {
                        $unique_search_ids = array_unique($search_ids);
                        $placeholders = implode(',', array_fill(0, count($unique_search_ids), '?'));
                        $types = str_repeat('s', count($unique_search_ids));
                        $stmt = $conn->prepare("SELECT c.id, c.status FROM chamados c JOIN mensagens m ON c.id = m.chamado_id WHERE m.message_id_header IN ($placeholders) LIMIT 1");
                        if (!$stmt) { throw new Exception("DB Prepare Error: " . $conn->error); }
                        $stmt->bind_param($types, ...$unique_search_ids);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $chamado_id = $row['id'];
                            $current_chamado_status = $row['status'];
                        }
                        $stmt->close();
                    }
                }

                if (!$chamado_id) {
                    $assunto_normalizado = get_normalized_subject($assunto_original);
                    $stmt = $conn->prepare("SELECT id, status FROM chamados WHERE email_cliente = ? AND (assunto = ? OR assunto LIKE ? OR assunto LIKE ?) ORDER BY id DESC LIMIT 1");
                    if (!$stmt) { throw new Exception("DB Prepare Error: " . $conn->error); }
                    $like_re = "Re: " . $assunto_normalizado; $like_RE = "RE: " . $assunto_normalizado;
                    $stmt->bind_param("ssss", $email_cliente, $assunto_normalizado, $like_re, $like_RE);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $chamado_id = $row['id'];
                        $current_chamado_status = $row['status'];
                    }
                    $stmt->close();
                }

                $is_new_ticket = false;
                if (!$chamado_id) {
                    if ($message_type === 'enviado') {
                         $conn->rollback();
                         imap_setflag_full($inbox, $email_uid, "\\Seen", ST_UID);
                         continue;
                    }

                    $is_new_ticket = true; 
                    $assigned_user_id = assignNextUserInRoundRobin($conn);
                    $stmt = $conn->prepare("INSERT INTO chamados (usuario_id, assunto, email_cliente, data_criacao, requer_atencao, categoria_id) VALUES (?, ?, ?, ?, 1, ?)");
                    if (!$stmt) { throw new Exception("DB Prepare Error: " . $conn->error); }
                    $stmt->bind_param("isssi", $assigned_user_id, $assunto_original, $email_cliente, $data_envio, $default_category_id);
                    if (!$stmt->execute()) { throw new Exception("DB Execute Error: " . $stmt->error); }
                    $chamado_id = $conn->insert_id;
                    $stmt->close();
                } else {
                    if ($message_type === 'recebido') {
                        $requer_atencao_update = ", requer_atencao = 1";
                        $status_update_sql = ($current_chamado_status === 'Fechado' || $current_chamado_status === 'Pendente') ? ", status = 'Aberto'" : "";
                        $sql_update = "UPDATE chamados SET ultimo_update = NOW() $status_update_sql $requer_atencao_update WHERE id = ?";
                        $stmt = $conn->prepare($sql_update);
                        if (!$stmt) { throw new Exception("DB Prepare Error: " . $conn->error); }
                        $stmt->bind_param("i", $chamado_id);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                         $sql_update = "UPDATE chamados SET ultimo_update = NOW() WHERE id = ?";
                         $stmt = $conn->prepare($sql_update);
                         if (!$stmt) { throw new Exception("DB Prepare Error: " . $conn->error); }
                         $stmt->bind_param("i", $chamado_id);
                         $stmt->execute();
                         $stmt->close();
                    }
                }

                $stmt_msg = $conn->prepare("INSERT INTO mensagens (chamado_id, message_id_header, in_reply_to_header, references_header, remetente_nome, remetente_email, destinatario_email, cc_emails, bcc_emails, corpo_html, corpo_texto, data_envio, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_msg) { throw new Exception("DB Prepare Error: " . $conn->error); }
                $stmt_msg->bind_param("issssssssssss", $chamado_id, $message_id, $in_reply_to, $references, $remetente_nome, $remetente_email, $destinatario_email, $cc_emails_str, $bcc_emails_str, $corpo_html, $corpo_texto, $data_envio, $message_type);
                if (!$stmt_msg->execute()) { throw new Exception("DB Execute Error: " . $stmt_msg->error); }
                $mensagem_id = $stmt_msg->insert_id;
                $stmt_msg->close();
                
                if (!empty($anexos)) {
                    $stmt_anexo = $conn->prepare("INSERT INTO anexos (mensagem_id, nome_arquivo, tipo_mime, tamanho_bytes, conteudo) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt_anexo) { throw new Exception("DB Prepare Error Anexo: " . $conn->error); }
                    foreach ($anexos as $anexo) {
                        $null = NULL;
                        $stmt_anexo->bind_param("issib", $mensagem_id, $anexo['nome'], $anexo['tipo'], $anexo['tamanho'], $null);
                        $stmt_anexo->send_long_data(4, $anexo['conteudo']);
                        if (!$stmt_anexo->execute()) { throw new Exception("DB Execute Error Anexo: " . $stmt_anexo->error); }
                    }
                    $stmt_anexo->close();
                }
                if (!empty($imagens_embutidas)) {
                    $stmt_img = $conn->prepare("INSERT INTO imagens_embutidas (mensagem_id, content_id, tipo_mime, conteudo) VALUES (?, ?, ?, ?)");
                    if (!$stmt_img) { throw new Exception("DB Prepare Error Imagem: " . $conn->error); }
                    foreach ($imagens_embutidas as $img) {
                        $null = NULL;
                        $stmt_img->bind_param("issb", $mensagem_id, $img['cid'], $img['tipo'], $null);
                        $stmt_img->send_long_data(3, $img['conteudo']);
                        if (!$stmt_img->execute()) { throw new Exception("DB Execute Error Imagem: " . $stmt_img->error); }
                    }
                    $stmt_img->close();
                }

                $conn->commit();
                imap_setflag_full($inbox, $email_uid, "\\Seen", ST_UID);
                
                if ($is_new_ticket && $message_type === 'recebido') {
                    // Prepara dados para a resposta automática
                    $newly_created_tickets[] = [
                        'chamado_id' => $chamado_id,
                        'incoming_message_id' => $message_id,
                        'remetente_nome' => $remetente_nome, // Passa o nome do remetente
                        'assunto_original' => $assunto_original, // Passa o assunto original
                    ];
                }

            } catch (Exception $e) {
                $conn->rollback();
                error_log("Erro FATAL na transação para e-mail UID #$email_uid: " . $e->getMessage() . "\n");
            }
        }
    } else {
        echo "Nenhum e-mail correspondente ao critério '" . strtoupper($search_criteria) . "' foi encontrado.\n";
    }
    imap_close($inbox);
    return $newly_created_tickets;
}

// --- Execução Principal ---

// Carrega todas as configurações do banco de dados de uma vez
$configs = [];
$stmt_config = $conn->prepare("SELECT config_key, config_value FROM sistema_config");
if ($stmt_config && $stmt_config->execute()) {
    $result_config = $stmt_config->get_result();
    while ($row_config = $result_config->fetch_assoc()) {
        $configs[$row_config['config_key']] = $row_config['config_value'];
    }
    $stmt_config->close();
} else {
    error_log("Erro ao carregar configurações do sistema: " . ($conn->error ?? 'Erro desconhecido'));
}

// Define as variáveis de configuração com valores padrão
$auto_reply_enabled        = (int)($configs['auto_reply_enabled'] ?? 0);
$auto_reply_message        = $configs['auto_reply_message'] ?? '';
$support_sender_name       = $configs['support_sender_name'] ?? 'Equipe de Suporte';
$auto_reply_subject_prefix = $configs['auto_reply_subject_prefix'] ?? 'Confirmação de Chamado';
$fetch_start_date          = $configs['email_fetch_start_date'] ?? '';
$last_fetch_timestamp      = $configs['last_fetch_timestamp'] ?? '';

// LÓGICA DE BUSCA INTELIGENTE
// --- LÓGICA DE BUSCA INTELIGENTE (VERSÃO CORRIGIDA) ---

$force_rescan = isset($_GET['force_rescan']) && $_GET['force_rescan'] === 'true';
$effective_start_date = null;

if ($force_rescan) {
    // Se o rescan for forçado, a data base é a única que importa.
    $effective_start_date = $fetch_start_date;
    // E limpamos o marcador antigo para reiniciar o ciclo.
    $conn->query("UPDATE sistema_config SET config_value = '' WHERE config_key = 'last_fetch_timestamp'");
} else {
    // Converte as datas para timestamps para poder comparar
    $base_start_timestamp = !empty($fetch_start_date) ? strtotime($fetch_start_date) : 0;
    $last_fetch_ts = !empty($last_fetch_timestamp) ? strtotime($last_fetch_timestamp) : 0;

    // A data de início efetiva será a MAIS RECENTE entre a data base e a data da última coleta
    $effective_timestamp = max($base_start_timestamp, $last_fetch_ts);
    
    if ($effective_timestamp > 0) {
        $effective_start_date = date('Y-m-d H:i:s', $effective_timestamp);
    }
}

// Lógica inteligente para definir o modo de busca
if (php_sapi_name() === 'cli' || (isset($_GET['source']) && $_GET['source'] === 'ajax')) {
    // Se estiver executando via linha de comando (.bat) ou pelo AJAX do index, o padrão é 'ALL'
    // para garantir que todos os e-mails sejam verificados (lidos ou não lidos).
    // A checagem de duplicidade no banco de dados cuidará para não reinserir.
    $mode = 'ALL';
} else {
    $mode = isset($_GET['mode']) && strtolower($_GET['mode']) === 'all' ? 'ALL' : 'UNSEEN';
}
$search_criteria = $mode;

if (!empty($effective_start_date)) {
    $imap_date = date('d-M-Y', strtotime($effective_start_date));
    $search_criteria .= ' SINCE "' . $imap_date . '"';
}

$inbox_path = IMAP_SERVER . IMAP_INBOX_MAILBOX;
$sent_path = IMAP_SERVER . IMAP_SENT_MAILBOX;

if (php_sapi_name() === 'cli') {
    $inbox_path .= '/novalidate-cert';
    $sent_path .= '/novalidate-cert';
}

$current_run_timestamp = date('Y-m-d H:i:s');

// Executa o processamento
$new_tickets = process_mailbox($conn, $inbox_path, 'recebido', $search_criteria);
process_mailbox($conn, $sent_path, 'enviado', $search_criteria);

// Envia respostas automáticas
if (!empty($new_tickets) && $auto_reply_enabled && !empty($auto_reply_message)) {
    echo "\n--- Enviando respostas automáticas para " . count($new_tickets) . " novo(s) chamado(s) ---\n";

    foreach ($new_tickets as $ticket_data) {
        if (!is_array($ticket_data) || !isset($ticket_data['chamado_id'])) {
            continue;
        }

        $stmt_new_ticket_info = $conn->prepare("SELECT email_cliente, data_criacao FROM chamados WHERE id = ?");
        if ($stmt_new_ticket_info) {
            $stmt_new_ticket_info->bind_param("i", $ticket_data['chamado_id']);
            $stmt_new_ticket_info->execute();
            $new_ticket_info = $stmt_new_ticket_info->get_result()->fetch_assoc();
            $stmt_new_ticket_info->close();

            if ($new_ticket_info) {
                $mail_auto_reply_main = new PHPMailer(true);
                try {
                    $mail_auto_reply_main->isSMTP();
                    $mail_auto_reply_main->Host       = SMTP_HOST;
                    $mail_auto_reply_main->SMTPAuth   = true;
                    $mail_auto_reply_main->Username   = SMTP_USER;
                    $mail_auto_reply_main->Password   = SMTP_PASS;
                    $mail_auto_reply_main->SMTPSecure = SMTP_SECURE;
                    $mail_auto_reply_main->Port       = SMTP_PORT;
                    $mail_auto_reply_main->CharSet    = 'UTF-8';

                    $mail_auto_reply_main->setFrom(SMTP_USER, $support_sender_name);
                    $mail_auto_reply_main->addAddress($new_ticket_info['email_cliente']);
                    $mail_auto_reply_main->Subject = $auto_reply_subject_prefix . ' - Ref: #' . $ticket_data['chamado_id'] . ' - ' . $ticket_data['assunto_original'];
                    
                    $mail_auto_reply_main->isHTML(true);

                    // ========================================================================
                    // INÍCIO DA CORREÇÃO E IMPLEMENTAÇÃO DAS TAGS
                    // ========================================================================
                    
                    $mensagem_modelo = $auto_reply_message;

                    $replacements = [
                        '{{nome_cliente}}'    => htmlspecialchars($ticket_data['remetente_nome']),
                        '{{numero_chamado}}'  => $ticket_data['chamado_id'],
                        '{{assunto_chamado}}' => htmlspecialchars($ticket_data['assunto_original']),
                        '{{data_abertura}}'   => date('d/m/Y H:i:s', strtotime($new_ticket_info['data_criacao']))
                    ];

                    $corpo_email_final = str_replace(
                        array_keys($replacements),
                        array_values($replacements),
                        $mensagem_modelo
                    );

                    $mail_auto_reply_main->Body    = $corpo_email_final;
                    $mail_auto_reply_main->AltBody = strip_tags($corpo_email_final);
                    
                    // ========================================================================
                    // FIM DA CORREÇÃO
                    // ========================================================================
                    
                    $auto_reply_message_id_main = '<auto.' . bin2hex(random_bytes(16)) . '@' . explode('@', SMTP_USER)[1] . '>';
                    $mail_auto_reply_main->MessageID = $auto_reply_message_id_main;
                    $mail_auto_reply_main->addCustomHeader('In-Reply-To', $ticket_data['incoming_message_id']);
                    $mail_auto_reply_main->addCustomHeader('References', $ticket_data['incoming_message_id']);
                    $mail_auto_reply_main->send();
                    echo "Resposta automática enviada para " . $new_ticket_info['email_cliente'] . " (Novo Chamado #" . $ticket_data['chamado_id'] . ").\n";

                    // Salva a mensagem automática no banco de dados (usando o corpo final com as tags substituídas)
                    $stmt_auto_msg_main = $conn->prepare("
                        INSERT INTO mensagens (
                            chamado_id, message_id_header, in_reply_to_header, references_header, 
                            remetente_nome, remetente_email, destinatario_email,
                            corpo_html, corpo_texto, data_envio, tipo
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'automatico')
                    ");

                    if ($stmt_auto_msg_main) {
                        $auto_msg_remetente_nome_main = $support_sender_name;
                        $auto_msg_remetente_email_main = SMTP_USER;
                        $auto_msg_destinatario_email_main = $new_ticket_info['email_cliente'];
                        $auto_msg_corpo_texto_main = strip_tags($corpo_email_final); // Usa o corpo final aqui também
                        $auto_reply_data_envio_main = date("Y-m-d H:i:s", strtotime($new_ticket_info['data_criacao'] . ' +1 second')); 
                        
                        $stmt_auto_msg_main->bind_param("isssssssss", 
                            $ticket_data['chamado_id'], 
                            $auto_reply_message_id_main, 
                            $ticket_data['incoming_message_id'],
                            $ticket_data['incoming_message_id'],
                            $auto_msg_remetente_nome_main, 
                            $auto_msg_remetente_email_main,
                            $auto_msg_destinatario_email_main,
                            $corpo_email_final, // Salva o corpo final no banco
                            $auto_msg_corpo_texto_main, 
                            $auto_reply_data_envio_main
                        );
                        
                        $stmt_auto_msg_main->execute();
                        $stmt_auto_msg_main->close();
                        echo "Registro da resposta automática salvo no DB para Chamado #" . $ticket_data['chamado_id'] . ".\n";
                    } else {
                        error_log("Erro ao preparar query para salvar auto-resposta: " . $conn->error);
                    }

                } catch (Exception $e) {
                    error_log("Erro ao enviar/salvar resposta automática para chamado #" . $ticket_data['chamado_id'] . ": " . $e->getMessage());
                }
            }
        }
    }
}
if (!$force_rescan) {
    // Atualiza o timestamp da última execução BEM-SUCEDIDA
    $stmt_update_timestamp = $conn->prepare("INSERT INTO sistema_config (config_key, config_value) VALUES ('last_fetch_timestamp', ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
    $stmt_update_timestamp->bind_param("s", $current_run_timestamp);
    $stmt_update_timestamp->execute();
    $stmt_update_timestamp->close();
    echo "\nScript finalizado. Marcador de última verificação atualizado para: " . $current_run_timestamp . "\n";
} else {
    echo "\nScript finalizado. Re-verificação completa executada.\n";
}

// ========================================================================
// INÍCIO DA CORREÇÃO: Busca e retorna os detalhes dos novos chamados
// ========================================================================

// Se a requisição veio do AJAX e se houver novos chamados criados...
if (isset($_GET['source']) && $_GET['source'] === 'ajax' && !empty($new_tickets)) {
    
    // Pega apenas os IDs dos novos chamados
    $new_ticket_ids = array_map(function($ticket) {
        return $ticket['chamado_id'];
    }, $new_tickets);

    // Prepara a query para buscar os detalhes completos desses novos chamados
    $placeholders = implode(',', array_fill(0, count($new_ticket_ids), '?'));
    $types = str_repeat('i', count($new_ticket_ids));

    $sql = "
        SELECT 
            c.id, c.assunto, c.email_cliente, c.data_criacao, c.status, c.prioridade,
            u.nome as nome_usuario, 
            cat.nome as nome_categoria,
            (SELECT COUNT(*) FROM mensagens m WHERE m.chamado_id = c.id) as message_count
        FROM chamados c
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        LEFT JOIN categorias_chamado cat ON c.categoria_id = cat.id
        WHERE c.id IN ($placeholders)
        ORDER BY c.id DESC
    ";
    
    $final_ticket_details = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$new_ticket_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()) {
            $final_ticket_details[] = $row;
        }
        $stmt->close();
    }

    // Retorna um JSON com os detalhes dos novos chamados
    header('Content-Type: application/json');
    ob_clean(); // Limpa qualquer saída de debug ("echo") anterior
    echo json_encode(['new_tickets' => $final_ticket_details]);
    exit;
}

// Se não for uma chamada AJAX, apenas retorna um JSON vazio para não quebrar a lógica
if (isset($_GET['source']) && $_GET['source'] === 'ajax') {
    header('Content-Type: application/json');
    ob_clean();
    echo json_encode(['new_tickets' => []]);
    exit;
}

// ========================================================================
// FIM DA CORREÇÃO
// ========================================================================
?>