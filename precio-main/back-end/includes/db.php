<?php
// Configuração de conexão com o banco.
// Ordem de prioridade: variáveis de ambiente (Docker) > db.local.php
// (gerado pelo script do XAMPP quando o MySQL usa porta diferente da padrão)
// > valores padrão do XAMPP (localhost:3306, root sem senha).

function precioEnv(string $key, string $default): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

$__dbLocal = is_file(__DIR__ . '/db.local.php') ? (require __DIR__ . '/db.local.php') : [];
if (!is_array($__dbLocal)) $__dbLocal = [];

if (!defined('DB_HOST'))    define('DB_HOST',    precioEnv('DB_HOST', (string) ($__dbLocal['host'] ?? 'localhost')));
if (!defined('DB_PORT'))    define('DB_PORT',    precioEnv('DB_PORT', (string) ($__dbLocal['port'] ?? '3306')));
if (!defined('DB_NAME'))    define('DB_NAME',    precioEnv('DB_NAME', 'precio_db'));
if (!defined('DB_USER'))    define('DB_USER',    precioEnv('DB_USER', 'root'));
if (!defined('DB_PASS'))    define('DB_PASS',    precioEnv('DB_PASS', ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
unset($__dbLocal);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Erro de conexão com o banco de dados.']));
        }
    }
    return $pdo;
}
