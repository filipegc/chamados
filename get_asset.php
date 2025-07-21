<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id']) || !isset($_GET['type'])) {
    http_response_code(400);
    die("Parâmetros inválidos.");
}

$id = (int)$_GET['id'];
$type = $_GET['type'];

if ($type == 'image') {
    $stmt = $conn->prepare("SELECT tipo_mime, conteudo FROM imagens_embutidas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($mime_type, $content);
        $stmt->fetch();
        
        header("Content-Type: " . $mime_type);
        echo $content;
    } else {
        http_response_code(404);
        die("Imagem não encontrada.");
    }
    $stmt->close();

} elseif ($type == 'attachment') {
    $stmt = $conn->prepare("SELECT nome_arquivo, tipo_mime, conteudo FROM anexos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($filename, $mime_type, $content);
        $stmt->fetch();
        
        header("Content-Type: " . $mime_type);
        header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
        echo $content;
    } else {
        http_response_code(404);
        die("Anexo não encontrado.");
    }
    $stmt->close();
} else {
    http_response_code(400);
    die("Tipo de asset inválido.");
}

$conn->close();
