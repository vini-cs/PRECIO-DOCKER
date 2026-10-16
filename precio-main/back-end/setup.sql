-- Criar banco de dados
CREATE DATABASE IF NOT EXISTS precio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE precio_db;

-- Tabela de usuários
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120)  NOT NULL,
    email      VARCHAR(191)  NOT NULL UNIQUE,
    password   VARCHAR(255)  NOT NULL,
    role       ENUM('admin','user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migração para bancos já existentes (ignore o erro se a coluna já existir):
-- ALTER TABLE users ADD COLUMN role ENUM('admin','user') NOT NULL DEFAULT 'user';

-- O primeiro usuário cadastrado no sistema é promovido a admin
-- automaticamente pelo back-end (ver api.php, ação "register").
-- Para promover manualmente um usuário existente:
-- UPDATE users SET role = 'admin' WHERE email = 'seu-email@exemplo.com';

-- Tabela de histórico de pesquisas realizadas no comparador
CREATE TABLE IF NOT EXISTS search_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NULL,
    term          VARCHAR(120) NOT NULL,
    results_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms   INT UNSIGNED NULL,
    source        ENUM('cache','live') NOT NULL DEFAULT 'live',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_log_created_at (created_at),
    CONSTRAINT fk_search_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Coluna de tempo (ms) de cada pesquisa — usada no dashboard ("tempo médio
-- das buscas") e no Histórico de Pesquisas (tempo de cada registro).
-- NOTA: todas as tabelas e colunas abaixo são criadas automaticamente pelo
-- sistema em tempo de execução (ver back-end/includes/migrations.php),
-- então rodar este arquivo manualmente é opcional — ele serve apenas como
-- referência/documentação da estrutura do banco.
-- (a coluna duration_ms e o índice idx_search_log_created_at já são criados
--  no CREATE TABLE acima, deixando este script idempotente: pode ser
--  executado várias vezes sem erro.)

-- ============================================================
-- MÓDULO DE CATÁLOGO (painel administrativo)
-- ============================================================

-- Categorias de produtos
CREATE TABLE IF NOT EXISTS categorias (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(100) NOT NULL,
    slug       VARCHAR(120) NOT NULL,
    ativo      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categorias_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lojas exibidas no site e usadas como referência pelo sistema de scraping
CREATE TABLE IF NOT EXISTS lojas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(120) NOT NULL,
    url        VARCHAR(255) NOT NULL,
    logo       VARCHAR(255) NULL,
    ativo      TINYINT(1)   NOT NULL DEFAULT 1,
    ordem      INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de produtos cadastrados manualmente pelo painel admin
CREATE TABLE IF NOT EXISTS produtos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome         VARCHAR(180) NOT NULL,
    categoria_id INT UNSIGNED NULL,
    imagem       VARCHAR(255) NULL,
    descricao    TEXT NULL,
    ativo        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_produtos_categoria (categoria_id),
    CONSTRAINT fk_produtos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações do sistema (chave/valor, valor sempre em JSON).
-- Substitui o antigo settings.json; o conteúdo do arquivo antigo (se
-- existir) é migrado automaticamente para esta tabela na primeira leitura.
CREATE TABLE IF NOT EXISTS settings (
    settings_key   VARCHAR(64) PRIMARY KEY,
    settings_value TEXT NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
