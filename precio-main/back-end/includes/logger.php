<?php
// Logger simples em arquivo texto, usado pelo painel administrativo
// para exibir eventos reais do sistema (login, erros de busca, etc).

define('LOG_FILE', __DIR__ . '/../logs/app.log');

function logMsg(string $level, string $source, string $message): void {
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }

    $level = strtoupper($level);
    $line = sprintf(
        "[%s] %s | %s | %s\n",
        date('Y-m-d H:i:s'),
        str_pad($level, 8),
        $source,
        str_replace(["\r", "\n"], ' ', $message)
    );

    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Lê as últimas $limit linhas do log, mais recentes primeiro.
 * Pode filtrar por nível (INFO, WARNING, ERROR, CRITICAL).
 */
function readLogs(int $limit = 200, ?string $levelFilter = null): array {
    if (!file_exists(LOG_FILE)) return [];

    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];

    $lines = array_reverse($lines);
    $entries = [];

    foreach ($lines as $line) {
        if (!preg_match('/^\[(.*?)\]\s+(\S+)\s+\|\s+(.*?)\s+\|\s+(.*)$/', $line, $m)) {
            continue;
        }
        [$_, $date, $level, $source, $message] = $m;
        $level = trim($level);

        if ($levelFilter && $levelFilter !== 'ALL' && $level !== $levelFilter) {
            continue;
        }

        $entries[] = [
            'date' => $date,
            'level' => $level,
            'source' => $source,
            'message' => $message,
        ];

        if (count($entries) >= $limit) break;
    }

    return $entries;
}

function clearLogs(): void {
    @file_put_contents(LOG_FILE, '');
}
