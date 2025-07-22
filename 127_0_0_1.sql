-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Tempo de geração: 22/07/2025 às 08:15
-- Versão do servidor: 8.3.0
-- Versão do PHP: 8.2.18

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `sistema_chamados`
--
CREATE DATABASE IF NOT EXISTS `sistema_chamados` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sistema_chamados`;

-- --------------------------------------------------------

--
-- Estrutura para tabela `anexos`
--

DROP TABLE IF EXISTS `anexos`;
CREATE TABLE IF NOT EXISTS `anexos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mensagem_id` int NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `tipo_mime` varchar(100) NOT NULL,
  `tamanho_bytes` int NOT NULL,
  `conteudo` mediumblob NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mensagem_id` (`mensagem_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `categorias_chamado`
--

DROP TABLE IF EXISTS `categorias_chamado`;
CREATE TABLE IF NOT EXISTS `categorias_chamado` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `chamados`
--

DROP TABLE IF EXISTS `chamados`;
CREATE TABLE IF NOT EXISTS `chamados` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `assunto` varchar(255) NOT NULL,
  `status` enum('Aberto','Pendente','Fechado') NOT NULL DEFAULT 'Aberto',
  `prioridade` enum('Baixa','Media','Alta') NOT NULL DEFAULT 'Media',
  `requer_atencao` tinyint(1) NOT NULL DEFAULT '1',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email_cliente` varchar(255) NOT NULL COMMENT 'Email do remetente original',
  `categoria_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `categoria_id` (`categoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `files`
--

DROP TABLE IF EXISTS `files`;
CREATE TABLE IF NOT EXISTS `files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_version_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `fk_current_version` (`current_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `file_versions`
--

DROP TABLE IF EXISTS `file_versions`;
CREATE TABLE IF NOT EXISTS `file_versions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_id` int NOT NULL,
  `version_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_id` (`file_id`,`version_number`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `imagens_embutidas`
--

DROP TABLE IF EXISTS `imagens_embutidas`;
CREATE TABLE IF NOT EXISTS `imagens_embutidas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mensagem_id` int NOT NULL,
  `content_id` varchar(255) NOT NULL COMMENT 'CID (Content-ID) da imagem',
  `tipo_mime` varchar(100) NOT NULL,
  `conteudo` mediumblob NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mensagem_cid` (`mensagem_id`,`content_id`(250))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `mensagens`
--

DROP TABLE IF EXISTS `mensagens`;
CREATE TABLE IF NOT EXISTS `mensagens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chamado_id` int NOT NULL,
  `message_id_header` varchar(255) NOT NULL COMMENT 'O Message-ID do cabeçalho do email',
  `in_reply_to_header` varchar(255) DEFAULT NULL COMMENT 'O In-Reply-To do cabeçalho',
  `references_header` text COMMENT 'O References do cabeçalho',
  `remetente_nome` varchar(255) DEFAULT NULL,
  `remetente_email` varchar(255) NOT NULL,
  `destinatario_email` text NOT NULL,
  `cc_emails` text,
  `bcc_emails` text,
  `corpo_html` mediumtext,
  `corpo_texto` mediumtext,
  `data_envio` datetime NOT NULL,
  `tipo` enum('recebido','enviado','interno') NOT NULL,
  `labels` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_id_header` (`message_id_header`(250)),
  KEY `chamado_id` (`chamado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `projects`
--

DROP TABLE IF EXISTS `projects`;
CREATE TABLE IF NOT EXISTS `projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`(250))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `sistema_config`
--

DROP TABLE IF EXISTS `sistema_config`;
CREATE TABLE IF NOT EXISTS `sistema_config` (
  `config_key` varchar(50) NOT NULL,
  `config_value` mediumtext,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `tickets`
--

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Pendente','Em Andamento','Concluído','Em Revisão','Cancelado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Pendente',
  `project_id` int DEFAULT NULL,
  `file_id` int DEFAULT NULL,
  `version_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `file_id` (`file_id`),
  KEY `version_id` (`version_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `role` enum('admin','atendente') NOT NULL DEFAULT 'atendente',
  `email_sender_name_type` enum('real_name','global_name') NOT NULL DEFAULT 'real_name' COMMENT 'Define o nome de exibição no e-mail: nome real do usuário ou nome global do suporte.',
  `signature_html` mediumtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`(250))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `anexos`
--
ALTER TABLE `anexos`
  ADD CONSTRAINT `fk_anexos_mensagem` FOREIGN KEY (`mensagem_id`) REFERENCES `mensagens` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `chamados`
--
ALTER TABLE `chamados`
  ADD CONSTRAINT `fk_chamados_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_chamado` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chamados_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `imagens_embutidas`
--
ALTER TABLE `imagens_embutidas`
  ADD CONSTRAINT `fk_imagens_embutidas_mensagem` FOREIGN KEY (`mensagem_id`) REFERENCES `mensagens` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `mensagens`
--
ALTER TABLE `mensagens`
  ADD CONSTRAINT `fk_mensagens_chamado` FOREIGN KEY (`chamado_id`) REFERENCES `chamados` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
