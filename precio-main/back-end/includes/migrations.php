<?php
// Garante que a estrutura do banco de dados esteja completa.
// Cria automaticamente as tabelas/colunas novas usadas pelo painel admin
// (categorias, lojas, produtos, settings, search_log.duration_ms) caso ainda
// não existam. É idempotente e seguro para rodar em toda requisição:
// nunca apaga ou sobrescreve dados já existentes.

function ensureSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;

    $db->exec("CREATE TABLE IF NOT EXISTS categorias (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome       VARCHAR(100) NOT NULL,
        slug       VARCHAR(120) NOT NULL,
        ativo      TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_categorias_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS lojas (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome       VARCHAR(120) NOT NULL,
        url        VARCHAR(255) NOT NULL,
        logo       VARCHAR(255) NULL,
        ativo      TINYINT(1)   NOT NULL DEFAULT 1,
        ordem      INT          NOT NULL DEFAULT 0,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS produtos (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome         VARCHAR(180) NOT NULL,
        categoria_id INT UNSIGNED NULL,
        imagem       VARCHAR(255) NULL,
        descricao    TEXT NULL,
        ativo        TINYINT(1)   NOT NULL DEFAULT 1,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_produtos_categoria (categoria_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        settings_key   VARCHAR(64) PRIMARY KEY,
        settings_value TEXT NULL,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // FK aplicada separadamente (idempotente): se produtos.categoria_id já
    // tiver dados órfãos de um ambiente legado, a criação da tabela acima
    // não falha por causa disso — só a FK (reforço extra) pode não ser criada,
    // e a regra de negócio correspondente já é garantida também na aplicação
    // (ver ação categorias_delete no admin_api.php).
    ensureForeignKey($db, 'produtos', 'fk_produtos_categoria', 'categoria_id', 'categorias', 'id');

    // Histórico de pesquisas: coluna nova para medir o tempo de cada busca
    // (usada no dashboard para "tempo médio das buscas" e na tela de
    // Histórico de Pesquisas para mostrar o tempo de cada registro).
    ensureColumn($db, 'search_log', 'duration_ms', 'INT UNSIGNED NULL AFTER results_count');

    $done = true;
}

/**
 * Adiciona uma coluna a uma tabela existente, apenas se ela ainda não existir.
 */
function ensureColumn(PDO $db, string $table, string $column, string $definitionSql): void
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definitionSql}");
        }
    } catch (\Throwable $e) {
        // Ambiente sem information_schema (ex.: testes automatizados com
        // SQLite) — a coluna pode já ter sido criada via CREATE TABLE.
    }
}

/**
 * Adiciona uma foreign key a uma tabela existente, apenas se ela ainda não existir.
 */
function ensureForeignKey(PDO $db, string $table, string $constraintName, string $column, string $refTable, string $refColumn = 'id'): void
{
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute([$table, $constraintName]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (`{$refColumn}`) ON DELETE SET NULL");
        }
    } catch (\Throwable $e) {
        // Se não for possível criar a FK (ex.: dados órfãos legados ou banco
        // sem suporte), o sistema continua funcionando normalmente.
    }
}
