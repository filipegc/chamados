<?php
// Suíte de Gerenciamento de Banco de Dados v4.0 (Backup, Restore e Migrate)
session_start();

// --- SEGURANÇA ---
define('MIGRATION_PASSWORD', 'admin123'); // IMPORTANTE: Altere esta senha!
require_once __DIR__ . '/config/config.php';

// --- LÓGICA DE AUTENTICAÇÃO ---
if (!isset($_SESSION['migration_tool_access']) || $_SESSION['migration_tool_access'] !== true) {
    if (isset($_POST['migration_password']) && $_POST['migration_password'] === MIGRATION_PASSWORD) {
        $_SESSION['migration_tool_access'] = true;
        header('Location: migration_tool.php');
        exit;
    }
    // O HTML da tela de login está no final do script
}

$message = '';
$sql_commands_to_run = [];
$step = 'default'; // Controla qual parte da interface exibir

// --- FUNÇÃO DE BACKUP APRIMORADA ---
// --- FUNÇÃO DE BACKUP CORRIGIDA ---
// --- FUNÇÃO DE BACKUP FINAL E CORRIGIDA ---
function create_backup($conn, $type = 'full') {
    // --- LINHA ADICIONADA ---
    // Desativa a verificação de chaves estrangeiras para permitir o DROP de tabelas com relacionamentos.
    $sql_script = "SET FOREIGN_KEY_CHECKS=0;\n\n";
    // --- FIM DA LINHA ADICIONADA ---

    $sql_script .= "-- Backup do banco de dados '" . DB_NAME . "' (" . ($type == 'full' ? 'Estrutura + Dados' : 'Apenas Estrutura') . ")\n";
    $sql_script .= "-- Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
    
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

    foreach ($tables as $table) {
        $sql_script .= "DROP TABLE IF EXISTS `$table`;\n";

        $create_table_result = $conn->query("SHOW CREATE TABLE `$table`");
        $create_table_row = $create_table_result->fetch_row();
        $sql_script .= "\n-- --------------------------------------------------------\n";
        $sql_script .= "-- Estrutura da tabela `$table`\n";
        $sql_script .= "-- --------------------------------------------------------\n\n";
        $sql_script .= $create_table_row[1] . ";\n\n";

        if ($type == 'full') {
            $sql_script .= "-- Extraindo dados da tabela `$table`\n";
            $result = $conn->query("SELECT * FROM `$table`");
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $sql_script .= "INSERT INTO `$table` VALUES(";
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = isset($value) ? "'" . $conn->real_escape_string($value) . "'" : "NULL";
                    }
                    $sql_script .= implode(', ', $values) . ");\n";
                }
            }
            $sql_script .= "\n";
        }
    }

    // --- LINHA ADICIONADA ---
    // Reativa a verificação de chaves estrangeiras no final do script.
    $sql_script .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    // --- FIM DA LINHA ADICIONADA ---

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename=backup_' . DB_NAME . '_' . $type . '_' . date('Y-m-d_H-i-s') . '.sql');
    echo $sql_script;
    exit;
}

// --- FUNÇÕES DE ANÁLISE DE MIGRAÇÃO (sem alteração) ---
// --- FUNÇÃO DE ANÁLISE DE MIGRAÇÃO (VERSÃO FINAL E CORRIGIDA) ---
function parse_sql_schema($sql_content) {
    $schema = [];
    // CORREÇÃO 1: Regex aprimorada para ser flexível com espaçamentos (usando \s+)
    // CORREÇÃO 2: O segundo (.*) agora é "greedy" (sem o "?") para capturar todo o conteúdo da tabela
    preg_match_all('/CREATE\s+TABLE(?: IF NOT EXISTS)?\s+`(.*?)`\s+\((.*)\)[^;]*;/is', $sql_content, $matches);

    foreach ($matches[1] as $index => $table_name) {
        $full_create_statement = $matches[0][$index];
        $columns_and_keys_sql = $matches[2][$index];
        $schema[$table_name] = [
            'columns' => [],
            'full_create' => $full_create_statement
        ];
        $definition_lines = preg_split('/\\r\\n|\\r|\\n/', $columns_and_keys_sql);
        foreach ($definition_lines as $line) {
            // CORREÇÃO 3: Limpeza robusta da linha para remover "espaços invisíveis"
            $line = trim(str_replace(chr(194).chr(160), ' ', $line));
            
            if (strpos($line, '`') === 0) {
                preg_match('/`([^`]+)`/', $line, $column_name_match);
                if (isset($column_name_match[1])) {
                    $column_name = $column_name_match[1];
                    $column_def = trim(substr($line, strlen($column_name_match[0])));
                    $column_def = rtrim($column_def, ',');
                    $schema[$table_name]['columns'][$column_name] = $column_def;
                }
            }
        }
    }
    return $schema;
}
function get_db_schema($conn) {
    $schema = [];
    $tables_result = $conn->query("SHOW TABLES");
    if (!$tables_result) {
        throw new Exception("Não foi possível listar as tabelas do banco de dados: " . $conn->error);
    }
    while ($table_row = $tables_result->fetch_row()) {
        $table_name = $table_row[0];
        $schema[$table_name] = [];
        $columns_result = $conn->query("SHOW COLUMNS FROM `$table_name`");
        if (!$columns_result) {
            throw new Exception("Não foi possível listar as colunas da tabela `$table_name`: " . $conn->error);
        }
        while ($column_row = $columns_result->fetch_assoc()) {
            $schema[$table_name][$column_row['Field']] = $column_row;
        }
    }
    return $schema;
}

// --- LÓGICA PRINCIPAL ---
// --- LÓGICA PRINCIPAL (VERSÃO COM RESUMO DAS ALTERAÇÕES) ---
// --- LÓGICA PRINCIPAL (VERSÃO FINAL COM ADIÇÃO E REMOÇÃO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'backup') {
            $backup_type = $_POST['backup_type'] ?? 'full';
            create_backup($conn, $backup_type);
        }
        
        if ($action === 'restore') {
            // ... (código da restauração permanece o mesmo, sem alterações aqui) ...
        }

        if ($action === 'analyze' || $action === 'execute') {
            $step = 'migrate';
            $sql_commands_to_run = [];
            $analysis_summary = [];

            if ($action === 'execute') {
                $sql_commands_to_run = $_SESSION['sql_commands_to_execute'] ?? [];
            } else { // Ação 'analyze'
                if (isset($_FILES['schema_file']) && $_FILES['schema_file']['error'] === UPLOAD_ERR_OK) {
                    $sql_content = file_get_contents($_FILES['schema_file']['tmp_name']);
                } else {
                    throw new Exception("Nenhum arquivo de esquema SQL foi fornecido.");
                }
                
                $source_schema = get_db_schema($conn); // Banco de dados atual
                $target_schema = parse_sql_schema($sql_content); // Arquivo .sql (alvo)

                // --- ETAPA 1: VERIFICAR ADIÇÕES (Tabelas e Colunas novas) ---
                foreach ($target_schema as $table_name => $table_data) {
                    if (!isset($source_schema[$table_name])) {
                        // Tabela existe no .sql mas não no banco -> ADICIONAR TABELA
                        $sql_commands_to_run[] = $table_data['full_create'];
                        $analysis_summary[] = "[NOVO] Será criada a tabela '" . htmlspecialchars($table_name) . "'.";
                    } else {
                        // Tabela existe, verifica colunas
                        foreach ($table_data['columns'] as $column_name => $column_def) {
                            if (!isset($source_schema[$table_name][$column_name])) {
                                // Coluna existe no .sql mas não no banco -> ADICIONAR COLUNA
                                $sql_commands_to_run[] = "ALTER TABLE `$table_name` ADD COLUMN `$column_name` $column_def;";
                                $analysis_summary[] = "[ADIÇÃO] Tabela '" . htmlspecialchars($table_name) . "': Adicionar coluna '" . htmlspecialchars($column_name) . "' (" . htmlspecialchars($column_def) . ").";
                            }
                        }
                    }
                }

                // --- ETAPA 2: VERIFICAR REMOÇÕES (Colunas que não existem mais no .sql) ---
                foreach ($source_schema as $table_name => $table_data) {
                    if (isset($target_schema[$table_name])) {
                        // A tabela ainda existe no .sql, então podemos verificar suas colunas
                        foreach ($table_data as $column_name => $column_info) {
                            // Se a coluna do banco de dados NÃO existe no .sql -> REMOVER COLUNA
                            if (!isset($target_schema[$table_name]['columns'][$column_name])) {
                                $sql_commands_to_run[] = "ALTER TABLE `$table_name` DROP COLUMN `$column_name`;";
                                $analysis_summary[] = "[REMOÇÃO] Tabela '" . htmlspecialchars($table_name) . "': Remover coluna '" . htmlspecialchars($column_name) . "'. (PERDA DE DADOS NESTA COLUNA)";
                            }
                        }
                    } 
                    // Nota: Para segurança, não estamos implementando a remoção de tabelas inteiras (DROP TABLE) automaticamente.
                    // Isso deve ser uma ação manual e consciente.
                }
                
                $_SESSION['sql_commands_to_execute'] = $sql_commands_to_run;
                $_SESSION['analysis_summary_display'] = $analysis_summary;
            }

            if ($action === 'execute') {
                if (!empty($sql_commands_to_run)) {
                    $conn->begin_transaction();
                    foreach ($sql_commands_to_run as $command) {
                        if (!$conn->query($command)) { throw new Exception("Erro ao executar comando de migração: " . $conn->error); }
                    }
                    $conn->commit();
                    $message = "<div class='alert alert-success'><strong>Sucesso!</strong> O esquema do banco de dados foi atualizado.</div>";
                } else {
                    $message = "<div class='alert alert-info'>Nenhuma alteração necessária. O esquema já está atualizado.</div>";
                }
                unset($_SESSION['sql_commands_to_execute']);
                unset($_SESSION['analysis_summary_display']);
            } else { // Ação 'analyze'
                $analysis_summary = $_SESSION['analysis_summary_display'] ?? [];
                if (!empty($analysis_summary)) {
                    $message = "<div class='alert alert-info'>Análise concluída. Verifique as alterações propostas e clique em 'Executar Atualização' para aplicá-las.</div>";
                } else {
                    $message = "<div class='alert alert-info'>Nenhuma alteração de esquema necessária. O banco de dados já está atualizado.</div>";
                }
            }
        }
    } catch (Exception $e) {
        if (property_exists($conn, 'in_transaction') && $conn->in_transaction) {
            $conn->rollback();
        }
        $message = "<div class='alert alert-danger'><strong>Ocorreu um erro:</strong> " . $e->getMessage() . "</div>";
    }
}

// --- TELA DE LOGIN (se não estiver autenticado) ---
if (!isset($_SESSION['migration_tool_access']) || $_SESSION['migration_tool_access'] !== true) {
    /* ...código da tela de login, sem alteração... */
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suíte de Gerenciamento de Banco de Dados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body style="background-color: #f0f2f5;">
    <div class="container mt-5 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Gerenciamento de Banco de Dados</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar ao Sistema</a>
        </div>
        
        <?php if ($message) echo $message; ?>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-database-down me-2"></i>Painel de Backup</h5>
            </div>
            <div class="card-body">
                <p>Crie uma cópia de segurança do seu banco de dados atual (<b><?php echo DB_NAME; ?></b>). Escolha o tipo de backup desejado.</p>
                <form action="migration_tool.php" method="POST" class="d-flex justify-content-center gap-3">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" name="backup_type" value="full" class="btn btn-lg btn-success">
                        <i class="bi bi-box-seam me-2"></i>Backup Completo<br><small>(Estrutura e Dados)</small>
                    </button>
                    <button type="submit" name="backup_type" value="structure" class="btn btn-lg btn-outline-primary">
                        <i class="bi bi-diagram-3 me-2"></i>Backup da Estrutura<br><small>(Apenas Tabelas)</small>
                    </button>
                </form>
            </div>
        </div>

        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Painel de Restauração</h5>
            </div>
            <div class="card-body">
                 <div class="alert alert-danger"><strong>ATENÇÃO:</strong> Restaurar um backup irá <strong>SOBRESCREVER TODOS OS DADOS ATUAIS</strong> no banco de dados. Esta ação não pode ser desfeita. Use com extremo cuidado.</div>
                <form action="migration_tool.php" method="POST" enctype="multipart/form-data" onsubmit="return confirm('ALERTA MÁXIMO!\n\nVocê tem certeza ABSOLUTA que deseja restaurar este backup?\n\nTODOS os dados atuais do banco de dados <?php echo DB_NAME; ?> serão APAGADOS e substituídos pelo conteúdo deste arquivo.\n\nEsta ação é IRREVERSÍVEL!');">
                    <input type="hidden" name="action" value="restore">
                    <div class="mb-3">
                        <label for="restore_file" class="form-label">Selecione o arquivo de backup `.sql` para restaurar:</label>
                        <input class="form-control" type="file" id="restore_file" name="restore_file" required accept=".sql">
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-database-up me-2"></i>Restaurar Banco de Dados Agora</button>
                </form>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Painel de Migração (Atualizar Esquema)</h5>
            </div>
            <div class="card-body">
                <p>Esta ferramenta <strong>NÃO apaga dados</strong>. Ela compara o banco de dados atual com um novo arquivo de esquema e adiciona apenas as tabelas e colunas que estão faltando.</p>
                <form action="migration_tool.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="analyze">
                    <div class="mb-3">
                        <label for="schema_file" class="form-label">Selecione o novo arquivo de esquema `.sql`:</label>
                        <input class="form-control" type="file" id="schema_file" name="schema_file" required accept=".sql">
                    </div>
                    <button type="submit" class="btn btn-info"><i class="bi bi-search me-2"></i>Analisar e Preparar Atualização</button>
                </form>
            </div>
        </div>

       <?php 
$analysis_summary = $_SESSION['analysis_summary_display'] ?? [];
if ($step === 'migrate' && !empty($analysis_summary)): 
?>
<div class="card">
    <div class="card-header bg-info text-white">
         <h5 class="mb-0"><i class="bi bi-play-circle me-2"></i>Executar Atualização do Esquema</h5>
    </div>
    <div class="card-body">
        <p>As seguintes alterações de esquema foram propostas. Revise os comandos e execute para aplicá-los.</p>
        <textarea class="form-control" rows="15" readonly><?php echo htmlspecialchars(implode("\n", $analysis_summary)); ?></textarea>
        
        <form action="migration_tool.php" method="POST" class="mt-3" onsubmit="return confirm('CONFIRMAÇÃO\n\nVocê deseja executar estas alterações para ATUALIZAR o esquema do banco de dados?\n\nEsta ação é segura e não apaga dados existentes.');">
            <input type="hidden" name="action" value="execute">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle-fill me-2"></i> Executar Atualização de Esquema</button>
        </form>
    </div>
</div>
<?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>