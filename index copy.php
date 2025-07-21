<script>
    // Armazena a referência do timer para poder resetá-lo
    let emailCheckInterval;
    let autoFetchIntervalSeconds = <?php echo (int)($configs['auto_fetch_interval_minutes'] ?? 10) * 60; ?>;

    // Função que atualiza o relógio e a barra de progresso
    function updateCountdown() {
        let secondsPassed = 0;
        const progressBar = document.getElementById('progress-bar-inner');
        const countdownElement = document.getElementById('countdown');

        // Reseta o timer anterior
        if (window.countdownTimer) {
            clearInterval(window.countdownTimer);
        }

        // Cria um novo timer
        window.countdownTimer = setInterval(() => {
            secondsPassed++;
            let percentage = (secondsPassed / autoFetchIntervalSeconds) * 100;
            progressBar.style.width = percentage + '%';
            
            let secondsRemaining = autoFetchIntervalSeconds - secondsPassed;
            let minutes = Math.floor(secondsRemaining / 60);
            let seconds = secondsRemaining % 60;
            countdownElement.textContent = `${minutes}m ${seconds.toString().padStart(2, '0')}s`;

            if (secondsPassed >= autoFetchIntervalSeconds) {
                secondsPassed = 0; // Reinicia a contagem para o próximo ciclo
            }
        }, 1000);
    }
    
    // Função que popula a tabela com os dados dos chamados
    function populateTable(chamados) {
        const tbody = document.getElementById('chamados-table-body');
        tbody.innerHTML = ''; // Limpa a tabela
        
        if (chamados.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Nenhum chamado encontrado.</td></tr>';
            return;
        }

        chamados.forEach(chamado => {
            let prioridadeBadge = '';
            switch(chamado.prioridade) {
                case 'Alta': prioridadeBadge = '<span class="badge bg-danger">Alta</span>'; break;
                case 'Média': prioridadeBadge = '<span class="badge bg-warning text-dark">Média</span>'; break;
                case 'Baixa': prioridadeBadge = '<span class="badge bg-success">Baixa</span>'; break;
                default: prioridadeBadge = `<span class="badge bg-secondary">${chamado.prioridade || 'N/D'}</span>`; break;
            }

            let statusBadge = '';
            switch(chamado.status) {
                case 'Aberto': statusBadge = '<span class="badge bg-primary">Aberto</span>'; break;
                case 'Pendente': statusBadge = '<span class="badge bg-info text-dark">Pendente</span>'; break;
                case 'Fechado': statusBadge = '<span class="badge bg-secondary">Fechado</span>'; break;
                default: statusBadge = `<span class="badge bg-dark">${chamado.status || 'N/D'}</span>`; break;
            }

            const row = `
                <tr>
                    <td><a href="view_ticket.php?id=${chamado.id}">#${chamado.id}</a></td>
                    <td>${chamado.assunto || 'Não Identificado'}</td>
                    <td>${chamado.email_cliente || 'Não Identificado'}</td>
                    <td>${new Date(chamado.data_criacao).toLocaleString('pt-BR')}</td>
                    <td>${statusBadge}</td>
                    <td>${prioridadeBadge}</td>
                    <td>${chamado.atendente_nome || '<span class="text-muted">Não Atribuído</span>'}</td>
                    <td>${chamado.categoria_nome || '<span class="text-muted">N/D</span>'}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }

    // Função que busca os chamados (usada no carregamento da página e nos filtros)
    function fetchAndPopulateTable() {
        const urlParams = new URLSearchParams(window.location.search);
        fetch(`get_tickets.php?${urlParams.toString()}`)
            .then(response => response.json())
            .then(data => {
                if(data.chamados) {
                    populateTable(data.chamados);
                }
            })
            .catch(error => console.error('Erro ao buscar chamados:', error));
    }

    // Função que verifica novos e-mails e atualiza a tabela com a resposta
    function checkForNewEmails() {
        const fetchStatus = document.getElementById('fetch-status');
        fetchStatus.innerHTML = '<i class="bi bi-arrow-repeat"></i> Verificando e-mails...';
        console.log("Iniciando verificação de e-mails...");

        const urlParams = new URLSearchParams(window.location.search);
        
        // Passa os filtros atuais para o fetch_emails.php
        fetch(`scripts/fetch_emails.php?source=ajax&${urlParams.toString()}`)
            .then(response => response.json())
            .then(data => {
                console.log("Verificação concluída.");
                if (data.status === 'success' && data.chamados) {
                    // Usa os dados retornados diretamente para popular a tabela
                    populateTable(data.chamados);
                }
                fetchStatus.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Verificação concluída';
                updateCountdown(); // Reinicia o relógio
            })
            .catch(error => {
                console.error('Erro ao verificar e-mails:', error);
                fetchStatus.textContent = 'Erro na verificação.';
            });
    }

    // Função para iniciar a verificação periódica
    function startAutoFetch() {
        if (emailCheckInterval) {
            clearInterval(emailCheckInterval);
        }
        emailCheckInterval = setInterval(checkForNewEmails, autoFetchIntervalSeconds * 1000);
    }
    
    // Event listener que roda quando a página carrega
    document.addEventListener('DOMContentLoaded', function() {
        fetchAndPopulateTable(); // Carrega a tabela inicialmente
        updateCountdown(); // Inicia o relógio da primeira vez
        startAutoFetch(); // Inicia o ciclo de atualização automática
    });
</script>