<?php
// Exibe todos os erros (útil durante o desenvolvimento)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define o fuso horário padrão
date_default_timezone_set('America/Sao_Paulo');

// --- CONFIGURAÇÕES DO BANCO DE DADOS ---
define('DB_HOST', 'localhost'); // ou o host do seu DB
define('DB_USER', 'root');      // seu usuário
define('DB_PASS', '');          // sua senha
define('DB_NAME', 'sistema_chamados');

// --- CONFIGURAÇÕES DE E-MAIL (IMAP) ---
define('IMAP_SERVER', '{imap.gmail.com:993/imap/ssl}');
define('IMAP_USER', 'suporte.cead.glpi@gmail.com');
define('IMAP_PASS', 'uosh rkfp ctlh vowd'); // Sua senha de APP
define('IMAP_INBOX_MAILBOX', 'INBOX');
define('IMAP_SENT_MAILBOX', '[Gmail]/E-mails enviados');

// CORREÇÃO: Alterado de 'INBOX/Processados' para 'Processados'.
// Isso criará um Marcador/Pasta no nível principal da sua conta do Gmail.
define('IMAP_PROCESSED_MAILBOX', 'Processados'); 

// --- CONFIGURAÇÕES DE E-MAIL (SMTP - PHPMailer) ---
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'suporte.cead.glpi@gmail.com');
define('SMTP_PASS', 'uosh rkfp ctlh vowd'); // Sua senha de APP
define('SMTP_PORT', 587); // Ou 465 se usar SMTPS
define('SMTP_SECURE', 'tls'); // 'tls' ou 'ssl'

// --- OUTRAS CONFIGURAÇÕES ---
define('BASE_URL', 'http://localhost/sistema-chamados'); // URL base do seu sistema

// --- CONEXÃO COM O BANCO DE DADOS ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Verifica a conexão
if ($conn->connect_error) {
    die("Falha na conexão com o banco de dados: " . $conn->connect_error);
}

// Define o charset da conexão para utf8mb4 para suportar todos os caracteres
$conn->set_charset("utf8mb4");

// --- INCLUI O AUTOLOAD DO COMPOSER ---
require_once __DIR__ . '/../vendor/autoload.php';
