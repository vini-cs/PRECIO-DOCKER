<?php
// Configurações do sistema, persistidas no banco de dados (tabela
// `settings`, chave/valor com valor em JSON). Antes ficavam em um arquivo
// settings.json; na primeira leitura após esta atualização, o conteúdo do
// arquivo antigo (se existir) é migrado automaticamente para o banco.

define('SETTINGS_FILE_LEGADO', __DIR__ . '/../config/settings.json');

function settingsDefaults(): array {
    return [
        'cache_ttl_minutes'  => 10,
        'max_resultados'     => 30,
        'nome_sistema'       => 'Precio',
        'logo_sistema'       => '',
        'status_sistema'     => 'online', // online | manutencao
        'meli_client_id'     => '',
        'meli_client_secret' => '',
        'vtex_lojas'         => [],
    ];
}

/**
 * Migração de compatibilidade: se existir um settings.json antigo e a
 * tabela `settings` ainda estiver vazia, copia o conteúdo dele para o
 * banco uma única vez. Não faz nada se já houver dados no banco.
 */
function migrarSettingsLegado(PDO $db): void {
    if (!file_exists(SETTINGS_FILE_LEGADO)) return;

    $existe = (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($existe > 0) return;

    $raw = @file_get_contents(SETTINGS_FILE_LEGADO);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) return;

    $stmt = $db->prepare('INSERT INTO settings (settings_key, settings_value) VALUES (?, ?)');
    foreach ($data as $key => $value) {
        $stmt->execute([(string) $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }
}

function getSettings(): array {
    $db = getDB();
    migrarSettingsLegado($db);

    $defaults = settingsDefaults();
    $rows = $db->query('SELECT settings_key, settings_value FROM settings')->fetchAll();

    $data = [];
    foreach ($rows as $row) {
        $data[$row['settings_key']] = json_decode((string) $row['settings_value'], true);
    }

    return array_merge($defaults, $data);
}

function getSetting(string $key)
{
    $settings = getSettings();
    return $settings[$key] ?? (settingsDefaults()[$key] ?? null);
}

/**
 * Lista de lojas VTEX cadastradas pelo painel admin (Configurações → Lojas VTEX).
 * Cada item: ['id' => int, 'nome' => string, 'dominio' => string, 'ativo' => bool].
 * Isso é independente da tabela `lojas` (que é o cadastro geral de lojas
 * exibidas no site); as lojas VTEX guardam o domínio técnico usado pelo
 * scraper para consultar a API pública de catálogo de cada loja.
 */
function getVtexLojas(): array {
    $lojas = getSetting('vtex_lojas');
    return is_array($lojas) ? $lojas : [];
}

function saveSettings(array $partial): array {
    $db = getDB();
    migrarSettingsLegado($db);

    $stmtSelect = $db->prepare('SELECT settings_key FROM settings WHERE settings_key = ?');
    $stmtInsert = $db->prepare('INSERT INTO settings (settings_key, settings_value) VALUES (?, ?)');
    $stmtUpdate = $db->prepare('UPDATE settings SET settings_value = ? WHERE settings_key = ?');

    foreach ($partial as $key => $value) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        $stmtSelect->execute([$key]);
        if ($stmtSelect->fetch()) {
            $stmtUpdate->execute([$encoded, $key]);
        } else {
            $stmtInsert->execute([$key, $encoded]);
        }
    }

    return getSettings();
}
