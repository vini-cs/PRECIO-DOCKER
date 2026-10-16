<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/products.php';
require_once __DIR__ . '/includes/migrations.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso restrito a administradores.']);
    exit;
}

$action = $_POST['action'] ?? '';
$db = getDB();
ensureSchema($db);

function paginate(array $items, int $page, int $perPage): array
{
    $page = max(1, $page);
    $total = count($items);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

function jsonInput(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

/**
 * Monta uma cláusula ORDER BY segura a partir de uma lista de colunas
 * permitidas (nunca a partir de entrada do usuário diretamente), usada
 * pelas telas com ordenação por coluna clicável.
 */
function sortClause(array $allowed, string $sort, string $dir): string
{
    $sort = in_array($sort, $allowed, true) ? $sort : $allowed[0];
    $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
    return "{$sort} {$dir}";
}

/**
 * Gera um slug (URL amigável) a partir de um texto: minúsculas, sem
 * acentos, apenas letras/números separados por hífen.
 */
function slugify(string $texto): string
{
    $texto = mb_strtolower(trim($texto));
    $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($semAcento !== false && $semAcento !== '') $texto = $semAcento;
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto) ?? '';
    $texto = trim($texto, '-');
    return $texto !== '' ? $texto : 'categoria';
}

// ── DASHBOARD ─────────────────────────────────────────────────────────────────
if ($action === 'stats') {
    $buscasTotal = (int) $db->query('SELECT COUNT(*) FROM search_log')->fetchColumn();
    $totalLojas = (int) $db->query('SELECT COUNT(*) FROM lojas')->fetchColumn();
    $totalProdutos = (int) $db->query('SELECT COUNT(*) FROM produtos WHERE ativo = 1')->fetchColumn();

    $tempoMedioMs = $db->query('SELECT AVG(duration_ms) FROM search_log WHERE duration_ms IS NOT NULL')->fetchColumn();
    $tempoMedioMs = ($tempoMedioMs !== null && $tempoMedioMs !== false) ? (int) round((float) $tempoMedioMs) : null;

    $ultimaBusca = $db->query('SELECT term, created_at FROM search_log ORDER BY created_at DESC LIMIT 1')->fetch();

    $stmt = $db->query("SELECT DATE(created_at) AS dia, COUNT(*) AS total FROM search_log WHERE created_at >= (CURDATE() - INTERVAL 6 DAY) GROUP BY dia ORDER BY dia ASC");
    $porDia = $stmt->fetchAll();
    $labelsDia = [];
    $valoresDia = [];
    for ($i = 6; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-{$i} day"));
        $labelsDia[] = date('d/m', strtotime($dia));
        $encontrado = array_values(array_filter($porDia, fn($r) => $r['dia'] === $dia));
        $valoresDia[] = $encontrado ? (int) $encontrado[0]['total'] : 0;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'buscas_total'       => $buscasTotal,
            'total_lojas'        => $totalLojas,
            'total_produtos'     => $totalProdutos,
            'tempo_medio_ms'     => $tempoMedioMs,
            'ultima_busca_termo' => $ultimaBusca['term'] ?? null,
            'ultima_busca_data'  => $ultimaBusca['created_at'] ?? null,
            'chart_buscas'       => ['labels' => $labelsDia, 'values' => $valoresDia],
        ],
    ]);
    exit;
}

// Visão geral de scrapers (relocada do Dashboard para manter o painel
// principal minimalista): distribuição de produtos em cache por loja,
// termos mais pesquisados e estatísticas de disco do cache.
if ($action === 'scrapers_overview') {
    $produtos = loadAllCachedProducts();
    $lojasCache = aggregateStores($produtos);
    $cache = cacheDiskStats();

    $stmt = $db->query('SELECT term, COUNT(*) AS total FROM search_log GROUP BY term ORDER BY total DESC LIMIT 5');
    $topTermos = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'cache_arquivos'   => $cache['arquivos'],
            'cache_tamanho_kb' => round($cache['tamanho_bytes'] / 1024, 1),
            'chart_lojas' => [
                'labels' => array_map(fn($l) => $l['loja'], $lojasCache),
                'values' => array_map(fn($l) => $l['produtos'], $lojasCache),
            ],
            'top_termos' => $topTermos,
        ],
    ]);
    exit;
}

// ── USUÁRIOS ──────────────────────────────────────────────────────────────────
if ($action === 'users_list') {
    $q = trim((string) jsonInput('q', ''));
    $page = (int) jsonInput('page', 1);

    if ($q !== '') {
        $stmt = $db->prepare('SELECT id, name, email, role, created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC');
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $db->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC');
    }

    $rows = $stmt->fetchAll();
    $result = paginate($rows, $page, 8);
    echo json_encode(['success' => true] + $result);
    exit;
}

if ($action === 'users_create') {
    $name = trim((string) jsonInput('name', ''));
    $email = trim((string) jsonInput('email', ''));
    $password = (string) jsonInput('password', '');
    $role = jsonInput('role', 'user') === 'admin' ? 'admin' : 'user';

    if (!$name || !$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Preencha nome, e-mail e senha.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'E-mail inválido.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'A senha deve ter ao menos 6 caracteres.']);
        exit;
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Já existe um usuário com este e-mail.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, $hash, $role]);

    logMsg('INFO', 'admin.users', "Usuário criado pelo painel: {$email} ({$role}).");
    echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso.']);
    exit;
}

if ($action === 'users_update') {
    $id = (int) jsonInput('id', 0);
    $name = trim((string) jsonInput('name', ''));
    $email = trim((string) jsonInput('email', ''));
    $role = jsonInput('role', 'user') === 'admin' ? 'admin' : 'user';
    $password = (string) jsonInput('password', '');

    if (!$id || !$name || !$email) {
        echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'E-mail inválido.']);
        exit;
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já pertence a outro usuário.']);
        exit;
    }

    // Impede remover o último administrador do sistema
    if ($role !== 'admin') {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if ($atual && $atual['role'] === 'admin') {
            $totalAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($totalAdmins <= 1) {
                echo json_encode(['success' => false, 'message' => 'Não é possível remover o último administrador.']);
                exit;
            }
        }
    }

    if ($password !== '') {
        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'A senha deve ter ao menos 6 caracteres.']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, password = ? WHERE id = ?');
        $stmt->execute([$name, $email, $role, $hash, $id]);
    } else {
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?');
        $stmt->execute([$name, $email, $role, $id]);
    }

    // Se o admin editou a própria conta, atualiza a sessão também
    if ($id === (int) $_SESSION['user_id']) {
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $role;
    }

    logMsg('INFO', 'admin.users', "Usuário #{$id} atualizado pelo painel.");
    echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso.']);
    exit;
}

if ($action === 'users_delete') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Usuário inválido.']);
        exit;
    }
    if ($id === (int) $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Você não pode excluir sua própria conta.']);
        exit;
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $alvo = $stmt->fetch();
    if (!$alvo) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
        exit;
    }
    if ($alvo['role'] === 'admin') {
        $totalAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($totalAdmins <= 1) {
            echo json_encode(['success' => false, 'message' => 'Não é possível excluir o último administrador.']);
            exit;
        }
    }

    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);

    logMsg('WARNING', 'admin.users', "Usuário #{$id} excluído pelo painel.");
    echo json_encode(['success' => true, 'message' => 'Usuário excluído com sucesso.']);
    exit;
}

// ── PRODUTOS (CATÁLOGO EM CACHE) ─────────────────────────────────────────────
if ($action === 'products_list') {
    $q = mb_strtolower(trim((string) jsonInput('q', '')));
    $loja = trim((string) jsonInput('loja', ''));
    $page = (int) jsonInput('page', 1);

    $produtos = loadAllCachedProducts();

    if ($q !== '') {
        $produtos = array_values(array_filter($produtos, fn($p) => str_contains(mb_strtolower($p['nome']), $q)));
    }
    if ($loja !== '') {
        $produtos = array_values(array_filter($produtos, fn($p) => $p['loja'] === $loja));
    }

    usort($produtos, fn($a, $b) => $b['updated_at'] <=> $a['updated_at']);

    $result = paginate($produtos, $page, 10);
    echo json_encode(['success' => true] + $result);
    exit;
}

if ($action === 'products_update') {
    $id = (string) jsonInput('id', '');
    $nome = trim((string) jsonInput('nome', ''));
    $preco = jsonInput('preco', null);
    $emEstoque = jsonInput('em_estoque', '1') === '1';

    if (!$id || !$nome || $preco === null || !is_numeric($preco)) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }

    $ok = updateCachedProduct($id, ['nome' => $nome, 'preco' => (float) $preco, 'em_estoque' => $emEstoque]);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Produto não encontrado no cache.']);
        exit;
    }

    logMsg('INFO', 'admin.products', "Produto atualizado no cache (id {$id}).");
    echo json_encode(['success' => true, 'message' => 'Produto atualizado com sucesso.']);
    exit;
}

if ($action === 'products_delete') {
    $id = (string) jsonInput('id', '');
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Produto inválido.']);
        exit;
    }

    $ok = deleteCachedProduct($id);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Produto não encontrado no cache.']);
        exit;
    }

    logMsg('WARNING', 'admin.products', "Produto removido do cache (id {$id}).");
    echo json_encode(['success' => true, 'message' => 'Produto removido do cache.']);
    exit;
}

// ── SCRAPERS / CACHE ──────────────────────────────────────────────────────────
if ($action === 'stores_list') {
    $produtos = loadAllCachedProducts();
    $lojas = aggregateStores($produtos);
    echo json_encode(['success' => true, 'data' => $lojas]);
    exit;
}

if ($action === 'cache_clear') {
    $removidos = clearAllCache();
    logMsg('WARNING', 'admin.cache', "Cache de buscas limpo manualmente pelo painel ({$removidos} arquivos removidos).");
    echo json_encode(['success' => true, 'message' => "Cache limpo: {$removidos} arquivo(s) removido(s)."]);
    exit;
}

// ── HISTÓRICO DE PESQUISAS ────────────────────────────────────────────────────
if ($action === 'searches_list') {
    $q = trim((string) jsonInput('q', ''));
    $dataInicio = trim((string) jsonInput('data_inicio', ''));
    $dataFim = trim((string) jsonInput('data_fim', ''));
    $page = (int) jsonInput('page', 1);
    $sort = sortClause(
        ['sl.created_at', 'sl.term', 'sl.results_count', 'sl.duration_ms'],
        (string) jsonInput('sort', 'sl.created_at'),
        (string) jsonInput('dir', 'DESC')
    );

    $sql = 'SELECT sl.id, sl.term, sl.results_count, sl.source, sl.duration_ms, sl.created_at, u.name AS user_name
            FROM search_log sl LEFT JOIN users u ON u.id = sl.user_id WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND sl.term LIKE ?';
        $params[] = '%' . $q . '%';
    }
    if ($dataInicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
        $sql .= ' AND DATE(sl.created_at) >= ?';
        $params[] = $dataInicio;
    }
    if ($dataFim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
        $sql .= ' AND DATE(sl.created_at) <= ?';
        $params[] = $dataFim;
    }
    $sql .= " ORDER BY {$sort} LIMIT 1000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = paginate($rows, $page, 10);
    echo json_encode(['success' => true] + $result);
    exit;
}

if ($action === 'searches_delete') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Registro inválido.']);
        exit;
    }

    $db->prepare('DELETE FROM search_log WHERE id = ?')->execute([$id]);

    logMsg('WARNING', 'admin.historico', "Registro de pesquisa #{$id} excluído pelo painel.");
    echo json_encode(['success' => true, 'message' => 'Registro excluído com sucesso.']);
    exit;
}

// ── LOGS ──────────────────────────────────────────────────────────────────────
if ($action === 'logs_list') {
    $level = jsonInput('level', 'ALL');
    $entries = readLogs(300, $level === 'ALL' ? null : $level);
    echo json_encode(['success' => true, 'data' => $entries]);
    exit;
}

if ($action === 'logs_clear') {
    clearLogs();
    logMsg('INFO', 'admin.logs', 'Log do sistema foi limpo pelo painel administrativo.');
    echo json_encode(['success' => true, 'message' => 'Log limpo com sucesso.']);
    exit;
}

// ── LOJAS VTEX ────────────────────────────────────────────────────────────────
if ($action === 'vtex_stores_list') {
    echo json_encode(['success' => true, 'data' => getVtexLojas()]);
    exit;
}

if ($action === 'vtex_store_add') {
    $nome    = trim((string) jsonInput('nome', ''));
    $dominio = trim((string) jsonInput('dominio', ''));
    $dominio = preg_replace('#^https?://#', '', $dominio);
    $dominio = rtrim((string) $dominio, '/');

    if (!$nome || !$dominio) {
        echo json_encode(['success' => false, 'message' => 'Informe o nome da loja e o domínio da API.']);
        exit;
    }

    $lojas = getVtexLojas();
    $novoId = 1;
    foreach ($lojas as $l) $novoId = max($novoId, ((int) $l['id']) + 1);

    $lojas[] = ['id' => $novoId, 'nome' => $nome, 'dominio' => $dominio, 'ativo' => true];
    saveSettings(['vtex_lojas' => $lojas]);

    logMsg('INFO', 'admin.vtex', "Loja VTEX adicionada: {$nome} ({$dominio}).");
    echo json_encode(['success' => true, 'message' => 'Loja adicionada com sucesso.', 'data' => $lojas]);
    exit;
}

if ($action === 'vtex_store_toggle') {
    $id = (int) jsonInput('id', 0);
    $lojas = getVtexLojas();
    $achou = false;
    foreach ($lojas as &$l) {
        if ((int) $l['id'] === $id) {
            $l['ativo'] = empty($l['ativo']);
            $achou = true;
        }
    }
    unset($l);

    if (!$achou) {
        echo json_encode(['success' => false, 'message' => 'Loja não encontrada.']);
        exit;
    }

    saveSettings(['vtex_lojas' => $lojas]);
    logMsg('INFO', 'admin.vtex', "Loja VTEX #{$id} teve o status alternado.");
    echo json_encode(['success' => true, 'message' => 'Status atualizado.', 'data' => $lojas]);
    exit;
}

if ($action === 'vtex_store_remove') {
    $id = (int) jsonInput('id', 0);
    $lojas = getVtexLojas();
    $restantes = array_values(array_filter($lojas, fn($l) => (int) $l['id'] !== $id));

    if (count($restantes) === count($lojas)) {
        echo json_encode(['success' => false, 'message' => 'Loja não encontrada.']);
        exit;
    }

    saveSettings(['vtex_lojas' => $restantes]);
    logMsg('WARNING', 'admin.vtex', "Loja VTEX #{$id} removida.");
    echo json_encode(['success' => true, 'message' => 'Loja removida com sucesso.', 'data' => $restantes]);
    exit;
}

// ── CATEGORIAS ────────────────────────────────────────────────────────────────
if ($action === 'categorias_list') {
    $q = trim((string) jsonInput('q', ''));
    $page = (int) jsonInput('page', 1);
    $sort = sortClause(['c.nome', 'c.slug', 'c.created_at'], (string) jsonInput('sort', 'c.nome'), (string) jsonInput('dir', 'ASC'));

    $sql = 'SELECT c.id, c.nome, c.slug, c.ativo, c.created_at,
                   (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id = c.id) AS total_produtos
            FROM categorias c WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (c.nome LIKE ? OR c.slug LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY {$sort}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = paginate($rows, $page, 10);
    echo json_encode(['success' => true] + $result);
    exit;
}

// Lista simplificada (id + nome) para preencher o <select> de categoria
// no formulário de produto.
if ($action === 'categorias_options') {
    $rows = $db->query('SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY nome ASC')->fetchAll();
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($action === 'categorias_create') {
    $nome = trim((string) jsonInput('nome', ''));
    $slugInput = trim((string) jsonInput('slug', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if ($nome === '' || mb_strlen($nome) > 100) {
        echo json_encode(['success' => false, 'message' => 'Informe um nome de categoria válido (até 100 caracteres).']);
        exit;
    }

    $slug = slugify($slugInput !== '' ? $slugInput : $nome);

    $stmt = $db->prepare('SELECT id FROM categorias WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Já existe uma categoria com este slug.']);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO categorias (nome, slug, ativo) VALUES (?, ?, ?)');
    $stmt->execute([$nome, $slug, $ativo]);

    logMsg('INFO', 'admin.categorias', "Categoria criada: {$nome} ({$slug}).");
    echo json_encode(['success' => true, 'message' => 'Categoria criada com sucesso.']);
    exit;
}

if ($action === 'categorias_update') {
    $id = (int) jsonInput('id', 0);
    $nome = trim((string) jsonInput('nome', ''));
    $slugInput = trim((string) jsonInput('slug', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if (!$id || $nome === '' || mb_strlen($nome) > 100) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }

    $slug = slugify($slugInput !== '' ? $slugInput : $nome);

    $stmt = $db->prepare('SELECT id FROM categorias WHERE slug = ? AND id != ? LIMIT 1');
    $stmt->execute([$slug, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Já existe outra categoria com este slug.']);
        exit;
    }

    $stmt = $db->prepare('UPDATE categorias SET nome = ?, slug = ?, ativo = ? WHERE id = ?');
    $stmt->execute([$nome, $slug, $ativo, $id]);

    logMsg('INFO', 'admin.categorias', "Categoria #{$id} atualizada.");
    echo json_encode(['success' => true, 'message' => 'Categoria atualizada com sucesso.']);
    exit;
}

if ($action === 'categorias_delete') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Categoria inválida.']);
        exit;
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM produtos WHERE categoria_id = ?');
    $stmt->execute([$id]);
    $vinculados = (int) $stmt->fetchColumn();
    if ($vinculados > 0) {
        echo json_encode(['success' => false, 'message' => "Não é possível excluir: há {$vinculados} produto(s) vinculado(s) a esta categoria."]);
        exit;
    }

    $db->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);

    logMsg('WARNING', 'admin.categorias', "Categoria #{$id} excluída.");
    echo json_encode(['success' => true, 'message' => 'Categoria excluída com sucesso.']);
    exit;
}

// ── LOJAS (cadastro geral, exibido no site e usado pelo scraping) ─────────────
if ($action === 'lojas_list') {
    $q = trim((string) jsonInput('q', ''));
    $page = (int) jsonInput('page', 1);
    $sort = sortClause(['ordem', 'nome', 'created_at'], (string) jsonInput('sort', 'ordem'), (string) jsonInput('dir', 'ASC'));

    $sql = 'SELECT id, nome, url, logo, ativo, ordem, created_at FROM lojas WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND nome LIKE ?';
        $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY {$sort}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = paginate($rows, $page, 10);
    echo json_encode(['success' => true] + $result);
    exit;
}

if ($action === 'lojas_create') {
    $nome = trim((string) jsonInput('nome', ''));
    $url = trim((string) jsonInput('url', ''));
    $logo = trim((string) jsonInput('logo', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if ($nome === '' || mb_strlen($nome) > 120) {
        echo json_encode(['success' => false, 'message' => 'Informe um nome de loja válido (até 120 caracteres).']);
        exit;
    }
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para a loja (ex: https://www.loja.com.br).']);
        exit;
    }
    if ($logo !== '' && !filter_var($logo, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para o logo da loja.']);
        exit;
    }

    $proximaOrdem = (int) $db->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM lojas')->fetchColumn();

    $stmt = $db->prepare('INSERT INTO lojas (nome, url, logo, ativo, ordem) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$nome, $url, $logo ?: null, $ativo, $proximaOrdem]);

    logMsg('INFO', 'admin.lojas', "Loja criada: {$nome}.");
    echo json_encode(['success' => true, 'message' => 'Loja criada com sucesso.']);
    exit;
}

if ($action === 'lojas_update') {
    $id = (int) jsonInput('id', 0);
    $nome = trim((string) jsonInput('nome', ''));
    $url = trim((string) jsonInput('url', ''));
    $logo = trim((string) jsonInput('logo', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if (!$id || $nome === '' || mb_strlen($nome) > 120) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para a loja.']);
        exit;
    }
    if ($logo !== '' && !filter_var($logo, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para o logo da loja.']);
        exit;
    }

    $stmt = $db->prepare('UPDATE lojas SET nome = ?, url = ?, logo = ?, ativo = ? WHERE id = ?');
    $stmt->execute([$nome, $url, $logo ?: null, $ativo, $id]);

    logMsg('INFO', 'admin.lojas', "Loja #{$id} atualizada.");
    echo json_encode(['success' => true, 'message' => 'Loja atualizada com sucesso.']);
    exit;
}

if ($action === 'lojas_delete') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Loja inválida.']);
        exit;
    }

    $db->prepare('DELETE FROM lojas WHERE id = ?')->execute([$id]);

    logMsg('WARNING', 'admin.lojas', "Loja #{$id} excluída.");
    echo json_encode(['success' => true, 'message' => 'Loja excluída com sucesso.']);
    exit;
}

if ($action === 'lojas_toggle') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Loja inválida.']);
        exit;
    }

    $db->prepare('UPDATE lojas SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);

    logMsg('INFO', 'admin.lojas', "Loja #{$id} teve o status alternado.");
    echo json_encode(['success' => true, 'message' => 'Status atualizado.']);
    exit;
}

if ($action === 'lojas_move') {
    $id = (int) jsonInput('id', 0);
    $direcao = jsonInput('direcao', '') === 'up' ? 'up' : 'down';

    $stmt = $db->prepare('SELECT id, ordem FROM lojas WHERE id = ?');
    $stmt->execute([$id]);
    $atual = $stmt->fetch();
    if (!$atual) {
        echo json_encode(['success' => false, 'message' => 'Loja inválida.']);
        exit;
    }

    if ($direcao === 'up') {
        $stmt = $db->prepare('SELECT id, ordem FROM lojas WHERE ordem < ? ORDER BY ordem DESC LIMIT 1');
    } else {
        $stmt = $db->prepare('SELECT id, ordem FROM lojas WHERE ordem > ? ORDER BY ordem ASC LIMIT 1');
    }
    $stmt->execute([$atual['ordem']]);
    $vizinho = $stmt->fetch();

    if (!$vizinho) {
        echo json_encode(['success' => true, 'message' => 'Esta loja já está na posição limite.']);
        exit;
    }

    $db->prepare('UPDATE lojas SET ordem = ? WHERE id = ?')->execute([$vizinho['ordem'], $atual['id']]);
    $db->prepare('UPDATE lojas SET ordem = ? WHERE id = ?')->execute([$atual['ordem'], $vizinho['id']]);

    logMsg('INFO', 'admin.lojas', "Ordem das lojas #{$atual['id']} e #{$vizinho['id']} foi trocada.");
    echo json_encode(['success' => true, 'message' => 'Ordem atualizada.']);
    exit;
}

// ── PRODUTOS (catálogo real em banco de dados) ───────────────────────────────
if ($action === 'catalogo_list') {
    $q = mb_strtolower(trim((string) jsonInput('q', '')));
    $categoriaId = (int) jsonInput('categoria_id', 0);
    $page = (int) jsonInput('page', 1);
    $sort = sortClause(
        ['p.nome', 'p.created_at', 'p.updated_at', 'p.ativo'],
        (string) jsonInput('sort', 'p.created_at'),
        (string) jsonInput('dir', 'DESC')
    );

    $sql = 'SELECT p.id, p.nome, p.categoria_id, p.imagem, p.descricao, p.ativo, p.created_at, p.updated_at,
                   c.nome AS categoria_nome
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE 1 = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND LOWER(p.nome) LIKE ?';
        $params[] = "%{$q}%";
    }
    if ($categoriaId > 0) {
        $sql .= ' AND p.categoria_id = ?';
        $params[] = $categoriaId;
    }
    $sql .= " ORDER BY {$sort}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = paginate($rows, $page, 10);
    echo json_encode(['success' => true] + $result);
    exit;
}

if ($action === 'catalogo_create') {
    $nome = trim((string) jsonInput('nome', ''));
    $categoriaId = (int) jsonInput('categoria_id', 0) ?: null;
    $imagem = trim((string) jsonInput('imagem', ''));
    $descricao = trim((string) jsonInput('descricao', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if ($nome === '' || mb_strlen($nome) > 180) {
        echo json_encode(['success' => false, 'message' => 'Informe um nome de produto válido (até 180 caracteres).']);
        exit;
    }
    if (mb_strlen($descricao) > 2000) {
        echo json_encode(['success' => false, 'message' => 'A descrição deve ter até 2000 caracteres.']);
        exit;
    }
    if ($imagem !== '' && !filter_var($imagem, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para a imagem do produto.']);
        exit;
    }
    if ($categoriaId) {
        $stmt = $db->prepare('SELECT id FROM categorias WHERE id = ?');
        $stmt->execute([$categoriaId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Categoria selecionada não existe.']);
            exit;
        }
    }

    $stmt = $db->prepare('INSERT INTO produtos (nome, categoria_id, imagem, descricao, ativo) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$nome, $categoriaId, $imagem ?: null, $descricao ?: null, $ativo]);

    logMsg('INFO', 'admin.catalogo', "Produto criado no catálogo: {$nome}.");
    echo json_encode(['success' => true, 'message' => 'Produto criado com sucesso.']);
    exit;
}

if ($action === 'catalogo_update') {
    $id = (int) jsonInput('id', 0);
    $nome = trim((string) jsonInput('nome', ''));
    $categoriaId = (int) jsonInput('categoria_id', 0) ?: null;
    $imagem = trim((string) jsonInput('imagem', ''));
    $descricao = trim((string) jsonInput('descricao', ''));
    $ativo = jsonInput('ativo', '1') === '1' ? 1 : 0;

    if (!$id || $nome === '' || mb_strlen($nome) > 180) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }
    if (mb_strlen($descricao) > 2000) {
        echo json_encode(['success' => false, 'message' => 'A descrição deve ter até 2000 caracteres.']);
        exit;
    }
    if ($imagem !== '' && !filter_var($imagem, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para a imagem do produto.']);
        exit;
    }
    if ($categoriaId) {
        $stmt = $db->prepare('SELECT id FROM categorias WHERE id = ?');
        $stmt->execute([$categoriaId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Categoria selecionada não existe.']);
            exit;
        }
    }

    $stmt = $db->prepare('UPDATE produtos SET nome = ?, categoria_id = ?, imagem = ?, descricao = ?, ativo = ? WHERE id = ?');
    $stmt->execute([$nome, $categoriaId, $imagem ?: null, $descricao ?: null, $ativo, $id]);

    logMsg('INFO', 'admin.catalogo', "Produto #{$id} atualizado no catálogo.");
    echo json_encode(['success' => true, 'message' => 'Produto atualizado com sucesso.']);
    exit;
}

if ($action === 'catalogo_delete') {
    $id = (int) jsonInput('id', 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Produto inválido.']);
        exit;
    }

    $db->prepare('DELETE FROM produtos WHERE id = ?')->execute([$id]);

    logMsg('WARNING', 'admin.catalogo', "Produto #{$id} excluído do catálogo.");
    echo json_encode(['success' => true, 'message' => 'Produto excluído com sucesso.']);
    exit;
}

// ── CONFIGURAÇÕES ─────────────────────────────────────────────────────────────
if ($action === 'settings_get') {
    echo json_encode(['success' => true, 'data' => getSettings()]);
    exit;
}

if ($action === 'settings_update') {
    $current = getSettings();

    $ttl = (int) jsonInput('cache_ttl_minutes', $current['cache_ttl_minutes']);
    if ($ttl < 1 || $ttl > 1440) {
        echo json_encode(['success' => false, 'message' => 'O tempo de cache deve ser entre 1 e 1440 minutos.']);
        exit;
    }

    $maxResultados = (int) jsonInput('max_resultados', $current['max_resultados']);
    if ($maxResultados < 1 || $maxResultados > 200) {
        echo json_encode(['success' => false, 'message' => 'A quantidade máxima de resultados deve ser entre 1 e 200.']);
        exit;
    }

    $nomeSistema = trim((string) jsonInput('nome_sistema', $current['nome_sistema']));
    if ($nomeSistema === '' || mb_strlen($nomeSistema) > 60) {
        echo json_encode(['success' => false, 'message' => 'O nome do sistema deve ter entre 1 e 60 caracteres.']);
        exit;
    }

    $logoSistema = trim((string) jsonInput('logo_sistema', $current['logo_sistema']));
    if ($logoSistema !== '' && !filter_var($logoSistema, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Informe uma URL válida para o logo do sistema.']);
        exit;
    }

    $statusSistema = jsonInput('status_sistema', $current['status_sistema']) === 'manutencao' ? 'manutencao' : 'online';

    $meliClientId = trim((string) jsonInput('meli_client_id', $current['meli_client_id']));
    $meliClientSecretInput = trim((string) jsonInput('meli_client_secret', ''));
    $meliClientSecret = $meliClientSecretInput !== '' ? $meliClientSecretInput : $current['meli_client_secret'];

    $updated = saveSettings([
        'cache_ttl_minutes'  => $ttl,
        'max_resultados'     => $maxResultados,
        'nome_sistema'       => $nomeSistema,
        'logo_sistema'       => $logoSistema,
        'status_sistema'     => $statusSistema,
        'meli_client_id'     => $meliClientId,
        'meli_client_secret' => $meliClientSecret,
    ]);

    logMsg('INFO', 'admin.settings', "Configurações atualizadas (status: {$statusSistema}, cache: {$ttl} min, máx. resultados: {$maxResultados}).");
    echo json_encode(['success' => true, 'message' => 'Configurações salvas.', 'data' => $updated]);
    exit;
}

// ── PERFIL DO ADMINISTRADOR LOGADO ───────────────────────────────────────────
if ($action === 'profile_update') {
    $name = trim((string) jsonInput('name', ''));
    $email = trim((string) jsonInput('email', ''));
    $password = (string) jsonInput('password', '');
    $id = (int) $_SESSION['user_id'];

    if (!$name || !$email) {
        echo json_encode(['success' => false, 'message' => 'Preencha nome e e-mail.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'E-mail inválido.']);
        exit;
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já está em uso.']);
        exit;
    }

    if ($password !== '') {
        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'A senha deve ter ao menos 6 caracteres.']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?');
        $stmt->execute([$name, $email, $hash, $id]);
    } else {
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
    }

    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;

    logMsg('INFO', 'admin.profile', "Perfil do administrador #{$id} atualizado.");
    echo json_encode(['success' => true, 'message' => 'Perfil atualizado com sucesso.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
