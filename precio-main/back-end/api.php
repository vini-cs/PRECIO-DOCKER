<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/migrations.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ── LOGIN ────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($_POST['email']    ?? '');
    // BUG FIX: não usar trim() em senha — espaços são válidos
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Preencha todos os campos.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        logMsg('WARNING', 'auth', "Tentativa de login falhou para {$email}.");
        echo json_encode(['success' => false, 'message' => 'E-mail ou senha incorretos.']);
        exit;
    }

    loginUser($user);
    logMsg('INFO', 'auth', "Login bem-sucedido: {$user['email']}.");
    echo json_encode(['success' => true, 'redirect' => 'home.php']);
    exit;
}

// ── CADASTRO ─────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    // BUG FIX: não usar trim() em senha — espaços são válidos
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (!$name || !$email || !$password || !$confirm) {
        echo json_encode(['success' => false, 'message' => 'Preencha todos os campos.']);
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
    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'As senhas não coincidem.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já está cadastrado.']);
        exit;
    }

    // O primeiro usuário cadastrado no sistema vira administrador.
    $totalUsuarios = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $role = $totalUsuarios === 0 ? 'admin' : 'user';

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, $hash, $role]);
    $id   = (int) $db->lastInsertId();

    loginUser(['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role]);
    logMsg('INFO', 'auth', "Novo cadastro: {$email}" . ($role === 'admin' ? ' (promovido a admin, primeiro usuário)' : '') . '.');
    echo json_encode(['success' => true, 'redirect' => 'home.php']);
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    logoutUser();
    echo json_encode(['success' => true, 'redirect' => 'index.php']);
    exit;
}

// ── CACHE ─────────────────────────────────────────────────────────────────────
// Cache simples em arquivo. TTL: 10 minutos. Evita buscas redundantes.
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_TTL', ((int) getSetting('cache_ttl_minutes')) * 60); // segundos, configurável no painel admin

function cacheGet(string $key): ?array
{
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > CACHE_TTL) {
        @unlink($file);
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cacheSet(string $key, array $data): void
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
        // Segurança: bloqueia acesso direto ao diretório
        @file_put_contents(CACHE_DIR . '/.htaccess', "Deny from all\n");
    }
    @file_put_contents(
        CACHE_DIR . '/' . md5($key) . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE)
    );

    // PERF FIX: sem isso, o diretório de cache crescia indefinidamente —
    // arquivos expirados só eram removidos se alguém buscasse exatamente
    // aquela chave de novo. Faz limpeza probabilística (1 em ~20 escritas)
    // para não pagar o custo de varrer o diretório em toda requisição.
    if (random_int(1, 20) === 1) {
        cacheGC();
    }
}

function cacheGC(): void
{
    $arquivos = @glob(CACHE_DIR . '/*.json');
    if (!$arquivos) return;
    $agora = time();
    foreach ($arquivos as $arquivo) {
        if ($agora - (@filemtime($arquivo) ?: 0) > CACHE_TTL) {
            @unlink($arquivo);
        }
    }
}

// ── MERCADO LIVRE: TOKEN DE APLICAÇÃO (OAuth client_credentials) ─────────────
// Guardado fora de CACHE_DIR/*.json (que é varrido pelo cacheGC acima) para
// não ser apagado junto com o cache de buscas — o token dura ~6h e tem seu
// próprio controle de expiração.
define('MELI_TOKEN_FILE', CACHE_DIR . '/tokens/meli_token.json');

/**
 * Retorna um access_token de aplicação do Mercado Livre, se houver
 * client_id/client_secret configurados no painel admin (Configurações).
 * Sem credenciais configuradas, retorna null e a busca segue usando o
 * endpoint público sem autenticação (funciona, mas com limite de taxa menor).
 */
function getMeliAccessToken(): ?string
{
    $clientId     = trim((string) getSetting('meli_client_id'));
    $clientSecret = trim((string) getSetting('meli_client_secret'));
    if ($clientId === '' || $clientSecret === '') {
        return null;
    }

    if (file_exists(MELI_TOKEN_FILE)) {
        $cached = json_decode((string) @file_get_contents(MELI_TOKEN_FILE), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60 && ($cached['client_id'] ?? null) === $clientId) {
            return $cached['access_token'];
        }
    }

    $ch = curl_init('https://api.mercadolibre.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT  => 6,
        CURLOPT_TIMEOUT         => 10,
        CURLOPT_SSL_VERIFYPEER  => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        logMsg('WARNING', 'mercadolivre', "Falha ao obter token de acesso do Mercado Livre (HTTP {$status}). A busca segue sem token (endpoint público).");
        return null;
    }

    $decoded   = json_decode((string) $body, true);
    $token     = $decoded['access_token'] ?? null;
    $expiresIn = (int) ($decoded['expires_in'] ?? 0);
    if (!$token) return null;

    if (!is_dir(dirname(MELI_TOKEN_FILE))) {
        @mkdir(dirname(MELI_TOKEN_FILE), 0755, true);
        @file_put_contents(dirname(MELI_TOKEN_FILE) . '/.htaccess', "Deny from all\n");
    }
    @file_put_contents(MELI_TOKEN_FILE, json_encode([
        'access_token' => $token,
        'client_id'    => $clientId,
        'expires_at'   => time() + max(60, $expiresIn - 120),
    ]));

    return $token;
}

// ── REQUISIÇÃO HTTP com cURL ──────────────────────────────────────────────────
// Retorna handle cURL configurado (sem executar ainda — para uso com curl_multi)
function buildCurlHandle(string $url, array $headers, string $postBody = '', string $method = 'GET')
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_ENCODING        => '',
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_CONNECTTIMEOUT  => 7,
        CURLOPT_TIMEOUT         => 18,
        CURLOPT_SSL_VERIFYPEER  => true,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $postBody;
    }
    curl_setopt_array($ch, $opts);
    return $ch;
}

// ── EXECUÇÃO PARALELA ─────────────────────────────────────────────────────────
// Executa múltiplos handles cURL em paralelo e retorna array de respostas
function curlMultiExec(array $handles): array
{
    $mh      = curl_multi_init();
    $results = [];

    foreach ($handles as $key => $ch) {
        curl_multi_add_handle($mh, $ch);
    }

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
    } while ($status === CURLM_CALL_MULTI_PERFORM); // drena chamadas pendentes sem bloquear

    while ($active && $status === CURLM_OK) {
        // PERF FIX: curl_multi_select pode retornar -1 em alguns ambientes
        // sem nunca relatar timeout; um pequeno usleep evita busy-loop nesse caso.
        if (curl_multi_select($mh, 0.5) === -1) {
            usleep(50000);
        }
        do {
            $status = curl_multi_exec($mh, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
    }

    foreach ($handles as $key => $ch) {
        $results[$key] = [
            'body'   => curl_multi_getcontent($ch),
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'error'  => curl_error($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ── LOJAS VTEX (API pública de catálogo) ─────────────────────────────────────
// Várias redes brasileiras (Centauro, Fast Shop, Lojas Colombo, entre outras)
// rodam na plataforma VTEX, que expõe um endpoint público de busca em JSON —
// sem necessidade de chave de API. O admin cadastra essas lojas pelo painel
// (Configurações → Lojas VTEX), informando um nome e o domínio do endpoint.
// A lista em si (getVtexLojas) vive em includes/settings.php.

/**
 * Monta a URL de busca da API pública VTEX a partir do domínio cadastrado.
 * Aceita tanto o domínio da própria loja (ex: www.centauro.com.br) quanto
 * o domínio direto da VTEX (ex: centauro.vtexcommercestable.com.br) —
 * ambos costumam expor o mesmo endpoint /api/catalog_system/pub/...
 */
function montarUrlBuscaVtex(string $dominio, string $query): string
{
    $dominio = trim($dominio);
    $dominio = preg_replace('#^https?://#', '', $dominio);
    $dominio = rtrim($dominio, '/');
    return 'https://' . $dominio . '/api/catalog_system/pub/products/search?ft=' . rawurlencode($query) . '&_from=0&_to=29';
}

/**
 * Interpreta a resposta JSON da API pública de catálogo VTEX.
 */
function parseVtexJson(string $body, int $status, string $nomeLoja): array
{
    if ($body === '' || $status === 0 || $status >= 400) {
        return ['produtos' => [], 'aviso' => ''];
    }

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return ['produtos' => [], 'aviso' => ''];
    }

    $produtos = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;

        $nome  = (string) ($item['productName'] ?? '');
        $link  = (string) ($item['link'] ?? '');
        $itens = $item['items'][0] ?? [];
        $oferta = $itens['sellers'][0]['commertialOffer'] ?? null;

        if ($nome === '' || $link === '' || !$oferta) continue;

        $preco     = (float) ($oferta['Price'] ?? 0);
        $precoOrig = (float) ($oferta['ListPrice'] ?? 0);
        if ($preco <= 0) continue;
        if ($precoOrig <= $preco) $precoOrig = 0;

        $imagem = $itens['images'][0]['imageUrl'] ?? ($itens['images'][0]['imageUrl'] ?? '');
        if ($imagem !== '') $imagem = str_replace('http://', 'https://', $imagem);

        $produtos[] = [
            'nome'       => $nome,
            'sku'        => (string) ($item['productId'] ?? ''),
            'preco'      => $preco,
            'preco_orig' => $precoOrig ?: null,
            'desconto'   => $precoOrig ? round((1 - $preco / $precoOrig) * 100, 1) : null,
            'url'        => $link,
            'imagem'     => $imagem,
            'em_estoque' => (int) ($oferta['AvailableQuantity'] ?? 0) > 0,
            'loja'       => $nomeLoja,
        ];
    }

    return ['produtos' => $produtos, 'aviso' => ''];
}

// FALLBACK de Mercado Livre via Jina Reader (quando a API oficial sofre rate
// limit). Assim como Magazine Luiza/Terabyte Shop, este parser foi escrito
// sem acesso a uma resposta real do Jina Reader para lista.mercadolivre.com.br
// — o ambiente onde este código foi gerado não tem acesso à internet para
// testar. Ele reaproveita o parser genérico (parseGenericLojaMarkdown), já
// calibrado para o formato "imagem → ruído → [nome](url) → preço" que o Jina
// Reader costuma produzir, tentando os dois domínios de produto mais comuns
// do Mercado Livre. Use test_scraper.php pra validar/ajustar contra uma
// resposta real antes de confiar 100% neste fallback em produção.
function parseMercadoLivreMarkdown(string $markdown, int $quantidade): array
{
    // Os dois domínios de produto mais comuns do Mercado Livre, casados numa
    // única passada (ver comentário em parseLojaMarkdownComPadraoDominio).
    $dominioPattern = '(?:https:\/\/www\.mercadolivre\.com\.br\/|https:\/\/produto\.mercadolivre\.com\.br\/)';
    return parseLojaMarkdownComPadraoDominio($markdown, $quantidade, $dominioPattern, 'Mercado Livre');
}

// ── BUSCA MERCADO LIVRE (API pública, JSON) ──────────────────────────────────
// Diferente das outras lojas, o Mercado Livre expõe uma API de busca oficial
// que devolve JSON estruturado diretamente — não precisa de proxy Jina nem
// de parsing de HTML/Markdown, então tende a ser a fonte mais estável.
function parseMercadoLivreJson(string $body, int $status): array
{
    if ($body === '' || $status === 0) {
        return ['produtos' => [], 'aviso' => ''];
    }

    if ($status === 401 || $status === 403) {
        logMsg('WARNING', 'mercadolivre', "Acesso negado pela API do Mercado Livre (HTTP {$status}). Verifique client_id/client_secret em Configurações.");
        return ['produtos' => [], 'aviso' => ''];
    }
    if ($status === 429) {
        logMsg('WARNING', 'mercadolivre', 'Rate limit da API do Mercado Livre atingido (HTTP 429). Tentando fallback via scraping.');
        return ['produtos' => [], 'aviso' => ''];
    }
    if ($status >= 400) {
        return ['produtos' => [], 'aviso' => ''];
    }

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['results']) || !is_array($decoded['results'])) {
        return ['produtos' => [], 'aviso' => ''];
    }

    $produtos = [];
    foreach ($decoded['results'] as $item) {
        $preco     = (float) ($item['price'] ?? 0);
        $precoOrig = isset($item['original_price']) ? (float) $item['original_price'] : null;
        if ($precoOrig !== null && $precoOrig <= $preco) {
            $precoOrig = null;
        }

        $imagem = (string) ($item['thumbnail'] ?? '');
        if ($imagem !== '') {
            // A API às vezes devolve a miniatura via http:// e em baixa resolução (...-I.jpg)
            $imagem = str_replace('http://', 'https://', $imagem);
            $imagem = preg_replace('/-I\.(jpg|jpeg|webp|png)$/i', '-O.$1', $imagem) ?? $imagem;
        }

        $produtos[] = [
            'nome'       => (string) ($item['title'] ?? ''),
            'sku'        => (string) ($item['id'] ?? ''),
            'preco'      => $preco,
            'preco_orig' => $precoOrig,
            'desconto'   => ($precoOrig && $precoOrig > 0) ? round((1 - $preco / $precoOrig) * 100, 1) : null,
            'url'        => (string) ($item['permalink'] ?? ''),
            'imagem'     => $imagem,
            'em_estoque' => (int) ($item['available_quantity'] ?? 0) > 0,
            'loja'       => 'Mercado Livre',
        ];
    }

    return ['produtos' => $produtos, 'aviso' => ''];
}

// ── BUSCA PICHAU (GraphQL) ────────────────────────────────────────────────────
function buscarPichau(string $query, int $quantidade = 12): array
{
    $graphql = <<<'GRAPHQL'
    query SearchProducts($search: String!, $pageSize: Int!) {
      products(search: $search, pageSize: $pageSize, sort: { relevance: DESC }) {
        total_count
        items {
          name
          sku
          url_key
          price_range {
            minimum_price {
              final_price { value currency }
              regular_price { value }
              discount { percent_off amount_off }
            }
          }
          small_image { url label }
          stock_status
        }
      }
    }
    GRAPHQL;

    $payload = json_encode([
        'query'     => $graphql,
        'variables' => ['search' => $query, 'pageSize' => $quantidade],
    ]);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Origin: https://www.pichau.com.br',
        'Referer: https://www.pichau.com.br/search?q=' . rawurlencode($query),
        'Store: default',
    ];

    $ch  = buildCurlHandle('https://www.pichau.com.br/graphql', $headers, $payload, 'POST');
    $res = curlMultiExec(['pichau' => $ch]);
    $r   = $res['pichau'];

    // Falhou ou bloqueado — fallback via Jina
    if ($r['body'] === '' || $r['body'] === false || $r['status'] >= 400
        || stripos((string)$r['body'], 'Just a moment') !== false
        || stripos((string)$r['body'], 'Cf-Mitigated')  !== false) {
        return buscarViaJina('https://www.pichau.com.br/search?q=' . rawurlencode($query), 'pichau', $query, $quantidade);
    }

    $data = json_decode($r['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE || isset($data['errors'])) {
        return buscarViaJina('https://www.pichau.com.br/search?q=' . rawurlencode($query), 'pichau', $query, $quantidade);
    }

    $products = $data['data']['products'] ?? [];
    $items    = $products['items'] ?? [];
    $total    = $products['total_count'] ?? 0;

    if (!$items) return ['total' => 0, 'produtos' => []];

    $produtos = [];
    foreach ($items as $item) {
        $precoInfo  = $item['price_range']['minimum_price'] ?? [];
        $precoFinal = $precoInfo['final_price']['value']  ?? 0;
        $precoOrig  = $precoInfo['regular_price']['value'] ?? 0;
        $desconto   = $precoInfo['discount']['percent_off'] ?? 0;
        $img        = $item['small_image'] ?? [];

        $produtos[] = [
            'nome'       => $item['name'] ?? '',
            'sku'        => $item['sku']  ?? '',
            'preco'      => $precoFinal,
            'preco_orig' => ($precoOrig && $precoOrig != $precoFinal) ? $precoOrig : null,
            'desconto'   => $desconto ? round($desconto, 1) : null,
            'url'        => 'https://www.pichau.com.br/' . ($item['url_key'] ?? ''),
            'imagem'     => $img['url'] ?? '',
            'em_estoque' => ($item['stock_status'] ?? 'OUT_OF_STOCK') === 'IN_STOCK',
            'loja'       => 'Pichau',
        ];
    }

    ordenarProdutosPorPreco($produtos);
    return ['total' => $total, 'produtos' => $produtos];
}

// ── FALLBACK VIA JINA (para KaBuM!, Amazon e Pichau quando API falha) ─────────
// BUG FIX: URL anterior era duplicada: 'r.jina.ai/http://r.jina.ai/http://' — correto é apenas 'r.jina.ai/'
function buscarViaJina(string $targetUrl, string $loja, string $query, int $quantidade = 12): array
{
    $readerUrl = 'https://r.jina.ai/' . $targetUrl;
    $headers   = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];

    $ch  = buildCurlHandle($readerUrl, $headers);
    $res = curlMultiExec([$loja => $ch]);
    $markdown = $res[$loja]['body'] ?? '';

    if (!is_string($markdown) || $markdown === '') {
        return ['total' => 0, 'produtos' => []];
    }

    return match($loja) {
        'pichau'       => parsePichauMarkdown($markdown, $quantidade),
        'mercadolivre' => parseMercadoLivreMarkdown($markdown, $quantidade),
        'kabum'        => parseKabumMarkdown($markdown, $quantidade),
        'amazon'       => parseAmazonMarkdown($markdown, $quantidade),
        'magalu'       => parseMagaluMarkdown($markdown, $quantidade),
        'terabyte'     => parseTerabyteMarkdown($markdown, $quantidade),
        default        => ['total' => 0, 'produtos' => []],
    };
}

function parsePichauMarkdown(string $markdown, int $quantidade): array
{
    $produtos = [];
    $vistos   = [];

    if (!preg_match_all('/\[!\[Image\s+\d+:\s*(.*?)\]\((https?:\/\/media\.pichau\.com\.br\/[^)]+)\)(.*?)\]\((https:\/\/www\.pichau\.com\.br\/[^)\s]+)\)/su', $markdown, $matches, PREG_SET_ORDER)) {
        return ['total' => 0, 'produtos' => [], 'mensagem' => 'Nenhum produto encontrado.'];
    }

    foreach ($matches as $match) {
        if (count($produtos) >= $quantidade) break;

        $imagem     = $match[2];
        $texto      = trim(preg_replace('/\s+/', ' ', $match[3]));
        $productUrl = $match[4];

        if (!preg_match('/##\s*(.*?)(?:\s+de\s+R\$|\s+R\$)/u', $texto, $nomeMatch)) continue;
        $nome = trim($nomeMatch[1]);
        if (!preg_match_all('/R\$([\d\.,]+)/u', $texto, $precosMatch) || !$precosMatch[1]) continue;

        $valores      = $precosMatch[1];
        $temPrecoDePor = stripos($texto, ' por R$') !== false && count($valores) > 1;
        $precoOrig    = $temPrecoDePor ? moedaParaFloat($valores[0]) : null;
        $preco        = moedaParaFloat($temPrecoDePor ? $valores[1] : $valores[0]);
        $key          = strtolower($productUrl);

        if (isset($vistos[$key])) continue;
        $vistos[$key] = true;

        $produtos[] = [
            'nome'       => $nome,
            'sku'        => '',
            'preco'      => $preco,
            'preco_orig' => $precoOrig && $precoOrig != $preco ? $precoOrig : null,
            'desconto'   => null,
            'url'        => $productUrl,
            'imagem'     => $imagem,
            'em_estoque' => true,
            'loja'       => 'Pichau',
        ];
    }

    if (!$produtos) return ['total' => 0, 'produtos' => [], 'mensagem' => 'Nenhum produto encontrado via fallback.'];

    ordenarProdutosPorPreco($produtos);
    return ['total' => count($produtos), 'produtos' => $produtos, 'aviso' => 'Resultados obtidos via fallback.'];
}

function parseKabumMarkdown(string $markdown, int $quantidade): array
{
    $produtos = [];
    $vistos   = [];
    $pattern  = '/!\[Image\s+\d+[^\]]*\]\((https?:\/\/images\.kabum\.com\.br\/[^)]+)\)(.*?)\]\((https:\/\/www\.kabum\.com\.br\/produto\/[^)\s]+)\)/su';

    if (!preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER)) return ['total' => 0, 'produtos' => []];

    foreach ($matches as $match) {
        if (count($produtos) >= $quantidade) break;

        $imagem = $match[1];
        $texto  = trim(preg_replace('/\s+/', ' ', $match[2]));
        $url    = $match[3];

        if (isset($vistos[$url])) continue;

        $partes = preg_split('/\s+R\$/u', $texto, 2);
        $nome   = trim($partes[0] ?? '');
        $nome   = preg_replace('/^(Produto Patrocinado\s+|Frete grátis\*\s+|Selo:[^ ]+\s+)/iu', '', $nome);
        $nome   = trim($nome);

        $trechoPreco = preg_split('/\s+(?:No PIX|À vista|Em até|ou\s+\d+x)\b/iu', $texto, 2)[0] ?? $texto;
        if ($nome === '' || !preg_match_all('/R\$ ?([\d\.,]+)/u', $trechoPreco, $precosMatch) || !$precosMatch[1]) continue;

        $valores   = $precosMatch[1];
        $preco     = moedaParaFloat(end($valores));
        $precoOrig = count($valores) > 1 ? moedaParaFloat($valores[0]) : null;

        $vistos[$url] = true;
        $produtos[] = [
            'nome'       => $nome,
            'sku'        => '',
            'preco'      => $preco,
            'preco_orig' => $precoOrig && $precoOrig != $preco ? $precoOrig : null,
            'desconto'   => null,
            'url'        => $url,
            'imagem'     => $imagem,
            'em_estoque' => true,
            'loja'       => 'KaBuM!',
        ];
    }

    ordenarProdutosPorPreco($produtos);
    return ['total' => count($produtos), 'produtos' => $produtos];
}

function parseAmazonMarkdown(string $markdown, int $quantidade): array
{
    $produtos = [];
    $vistos   = [];
    $pattern  = '/!\[Image\s+\d+:\s*(.*?)\]\((https?:\/\/m\.media-amazon\.com\/[^)]+)\).*?##\s*\[(.*?)\]\((https:\/\/www\.amazon\.com\.br\/[^)\s]+)\)(.*?)(?=!\[Image\s+\d+:|$)/su';

    if (!preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER)) return ['total' => 0, 'produtos' => []];

    foreach ($matches as $match) {
        if (count($produtos) >= $quantidade) break;

        $nome   = trim(preg_replace('/\s+/', ' ', $match[3] ?: $match[1]));
        $imagem = $match[2];
        $url    = $match[4];
        $texto  = trim(preg_replace('/\s+/', ' ', $match[5]));

        $trechoPreco = preg_split('/\s+(?:Entrega|Frete|Enviado|Apenas|Mais opções|Comprar)\b/iu', $texto, 2)[0] ?? $texto;
        if ($nome === '' || strlen($nome) > 220 || str_contains($nome, '](') || isset($vistos[$url]) || !preg_match('/R\$ ?([\d\.,]+)/u', $trechoPreco, $precoMatch)) continue;

        $vistos[$url] = true;
        $produtos[] = [
            'nome'       => $nome,
            'sku'        => '',
            'preco'      => moedaParaFloat($precoMatch[1]),
            'preco_orig' => null,
            'desconto'   => null,
            'url'        => $url,
            'imagem'     => $imagem,
            'em_estoque' => true,
            'loja'       => 'Amazon',
        ];
    }

    ordenarProdutosPorPreco($produtos);
    return ['total' => count($produtos), 'produtos' => $produtos];
}

// ── MAGAZINE LUIZA e TERABYTE SHOP ────────────────────────────────────────────
// IMPORTANTE: ao contrário dos parsers de Pichau/KaBuM!/Amazon (que foram
// calibrados e ajustados em cima de respostas reais do Jina Reader), estes
// dois foram escritos sem acesso a uma resposta real dessas lojas — o
// ambiente onde este código foi gerado não tem acesso à internet para testar
// contra magazineluiza.com.br / terabyteshop.com.br / r.jina.ai.
// Por isso o parser é deliberadamente mais tolerante (genérico) do que os
// outros. Use o script test_scraper.php (na raiz do projeto) para rodar
// contra os sites reais, ver o markdown bruto salvo em debug_<loja>.md e
// ajustar o regex de parseGenericLojaMarkdown() caso o layout real não bata.
function parseMagaluMarkdown(string $markdown, int $quantidade): array
{
    return parseGenericLojaMarkdown($markdown, $quantidade, 'https://www.magazineluiza.com.br/', 'Magazine Luiza');
}

function parseTerabyteMarkdown(string $markdown, int $quantidade): array
{
    return parseGenericLojaMarkdown($markdown, $quantidade, 'https://www.terabyteshop.com.br/', 'Terabyte Shop');
}

// Parser genérico: procura um bloco "imagem -> ... -> [nome do produto](url da loja) -> ... preço em R$"
// Mais tolerante que os parsers dedicados porque não conhecemos o HTML/markdown exato dessas lojas.
function parseGenericLojaMarkdown(string $markdown, int $quantidade, string $dominioLoja, string $nomeLoja): array
{
    return parseLojaMarkdownComPadraoDominio($markdown, $quantidade, preg_quote($dominioLoja, '/'), $nomeLoja);
}

// Núcleo do parser genérico, mas aceitando um padrão de domínio já em forma de
// regex (não escapado) — permite casar mais de um domínio de produto em uma
// única passada (ex.: Mercado Livre usa tanto www.mercadolivre.com.br quanto
// produto.mercadolivre.com.br).
//
// BUG FIX (calibrado contra uma resposta REAL da Terabyte Shop via Jina Reader):
// a estrutura real não é "imagem -> ... -> [nome](url)" (dois links
// separados) como a versão anterior assumia — é um ÚNICO link que embrulha
// tudo: [![Image N](imgurl) Nome ★★★★★4.8(42) 🔥 em promoção Marca De: R$ X
// R$ Y 12x R$ Z](url do produto). Isso é o mesmo formato que os parsers
// dedicados de KaBuM!/Amazon já tratavam corretamente; este parser genérico
// estava com um formato errado e por isso zerava os resultados de
// Magazine Luiza/Terabyte/Mercado Livre sempre que a fonte principal falhava.
function parseLojaMarkdownComPadraoDominio(string $markdown, int $quantidade, string $dominioPattern, string $nomeLoja): array
{
    $produtos = [];
    $vistos   = [];

    // imagem -> texto (nome + rating + badges + preço), tudo dentro do mesmo
    // link -> ](url do produto na loja)
    $pattern = '/!\[(?:Image\s*\d+)?[^\]]*\]\((https?:\/\/[^)\s]+?\.(?:jpg|jpeg|png|webp)[^)\s]*)\)'
             . '(.{0,700}?)'
             . '\]\((' . $dominioPattern . '[^)\s]+)\)/su';

    if (!preg_match_all($pattern, $markdown, $matches, PREG_SET_ORDER)) {
        return ['total' => 0, 'produtos' => []];
    }

    foreach ($matches as $match) {
        if (count($produtos) >= $quantidade) break;

        $imagem = $match[1];
        $texto  = trim(preg_replace('/\s+/u', ' ', $match[2]));
        $url    = $match[3];

        if (isset($vistos[$url])) continue;

        $emEstoque = !preg_match('/\bESGOTADO\b/u', $texto);

        // O nome termina no primeiro marcador de rating/selo/preço.
        $nome = trim(preg_split('/\s*(?:★|ESGOTADO\b|🔥|Frete Grátis|CUPOM|De:\s*R\$)/u', $texto, 2)[0] ?? $texto);
        $nome = html_entity_decode($nome, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($nome === '' || strlen($nome) > 220 || str_contains($nome, '](')) continue;

        // Preço: formato "De: R$ ORIGINAL R$ ATUAL 12x R$ parcela" (Terabyte/Magalu).
        $preco = null;
        $precoOrig = null;
        if (preg_match('/De:\s*R\$\s*([\d\.,]+)(?:\s+R\$\s*([\d\.,]+))?/u', $texto, $deMatch)) {
            if (isset($deMatch[2]) && $deMatch[2] !== '') {
                $precoOrig = moedaParaFloat($deMatch[1]);
                $preco     = moedaParaFloat($deMatch[2]);
            } else {
                $preco = moedaParaFloat($deMatch[1]);
            }
        } elseif (preg_match_all('/R\$ ?([\d\.,]+)/u', $texto, $precosMatch) && $precosMatch[1]) {
            // Fallback para lojas sem o rótulo "De:" (ex.: preço único, ou padrão diferente)
            $valores   = $precosMatch[1];
            $preco     = moedaParaFloat(end($valores));
            $precoOrig = count($valores) > 1 ? moedaParaFloat($valores[0]) : null;
        } else {
            continue;
        }

        if ($preco === null || $preco <= 0) continue;
        if ($precoOrig !== null && $precoOrig <= $preco) $precoOrig = null;

        $vistos[$url] = true;
        $produtos[] = [
            'nome'       => $nome,
            'sku'        => '',
            'preco'      => $preco,
            'preco_orig' => $precoOrig,
            'desconto'   => null,
            'url'        => $url,
            'imagem'     => $imagem,
            'em_estoque' => $emEstoque,
            'loja'       => $nomeLoja,
        ];
    }

    ordenarProdutosPorPreco($produtos);
    return ['total' => count($produtos), 'produtos' => $produtos];
}

// ── BUSCA PARALELA NAS LOJAS ──────────────────────────────────────────────────
function buscarProdutosMultisite(string $query): array
{
    // 1. Monta os handles cURL em paralelo (Pichau via GraphQL, Mercado Livre via
    // API oficial, e KaBuM/Amazon/Magalu/Terabyte via proxy Jina)
    $graphql = <<<'GRAPHQL'
    query SearchProducts($search: String!, $pageSize: Int!) {
      products(search: $search, pageSize: $pageSize, sort: { relevance: DESC }) {
        total_count
        items {
          name sku url_key
          price_range { minimum_price { final_price { value } regular_price { value } discount { percent_off } } }
          small_image { url }
          stock_status
        }
      }
    }
    GRAPHQL;

    $payload     = json_encode(['query' => $graphql, 'variables' => ['search' => $query, 'pageSize' => 30]]);
    $pichauHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Language: pt-BR,pt;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Origin: https://www.pichau.com.br',
        'Referer: https://www.pichau.com.br/search?q=' . rawurlencode($query),
        'Store: default',
    ];
    $jinaHeaders = [
        'Accept: text/html,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ];

    // Mercado Livre tem API pública de busca (JSON direto, sem precisar de
    // proxy/parsing de HTML). Um client_id/client_secret configurado no painel
    // admin (Configurações) é opcional — sem ele, a busca usa o endpoint
    // público mesmo assim, só que com um limite de requisições menor.
    $meliToken   = getMeliAccessToken();
    $meliHeaders = [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (compatible; PrecioBot/1.0; +https://precio.local)',
    ];
    if ($meliToken) {
        $meliHeaders[] = 'Authorization: Bearer ' . $meliToken;
    }

    $handles = [
        'pichau'       => buildCurlHandle('https://www.pichau.com.br/graphql', $pichauHeaders, $payload, 'POST'),
        'mercadolivre' => buildCurlHandle('https://api.mercadolibre.com/sites/MLB/search?q=' . rawurlencode($query) . '&limit=30', $meliHeaders),
        'kabum'        => buildCurlHandle('https://r.jina.ai/https://www.kabum.com.br/busca/' . rawurlencode($query), $jinaHeaders),
        'amazon'       => buildCurlHandle('https://r.jina.ai/https://www.amazon.com.br/s?k=' . rawurlencode($query), $jinaHeaders),
        'magalu'       => buildCurlHandle('https://r.jina.ai/https://www.magazineluiza.com.br/busca/' . rawurlencode($query) . '/', $jinaHeaders),
        'terabyte'     => buildCurlHandle('https://r.jina.ai/https://www.terabyteshop.com.br/busca?str=' . rawurlencode($query), $jinaHeaders),
    ];

    // Lojas VTEX cadastradas pelo admin (Configurações → Lojas VTEX) entram
    // dinamicamente na mesma leva de requisições paralelas.
    $vtexHeaders = ['Accept: application/json', 'User-Agent: Mozilla/5.0 (compatible; PrecioBot/1.0)'];
    $vtexLojas   = array_values(array_filter(getVtexLojas(), fn($l) => !empty($l['ativo']) && !empty($l['dominio'])));
    foreach ($vtexLojas as $loja) {
        $handles['vtex_' . $loja['id']] = buildCurlHandle(montarUrlBuscaVtex($loja['dominio'], $query), $vtexHeaders);
    }

    // 2. Dispara todos em paralelo
    $responses = curlMultiExec($handles);

    // 3. Processa Pichau
    $pichauRes  = $responses['pichau'];
    $pichauData = [];
    if ($pichauRes['body'] !== '' && $pichauRes['status'] < 400
        && stripos((string)$pichauRes['body'], 'Just a moment') === false) {
        $decoded = json_decode($pichauRes['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && !isset($decoded['errors'])) {
            $items = $decoded['data']['products']['items'] ?? [];
            foreach ($items as $item) {
                $precoInfo  = $item['price_range']['minimum_price'] ?? [];
                $precoFinal = $precoInfo['final_price']['value']   ?? 0;
                $precoOrig  = $precoInfo['regular_price']['value'] ?? 0;
                $desconto   = $precoInfo['discount']['percent_off'] ?? 0;
                $pichauData[] = [
                    'nome'       => $item['name'] ?? '',
                    'sku'        => $item['sku']  ?? '',
                    'preco'      => $precoFinal,
                    'preco_orig' => ($precoOrig && $precoOrig != $precoFinal) ? $precoOrig : null,
                    'desconto'   => $desconto ? round($desconto, 1) : null,
                    'url'        => 'https://www.pichau.com.br/' . ($item['url_key'] ?? ''),
                    'imagem'     => $item['small_image']['url'] ?? '',
                    'em_estoque' => ($item['stock_status'] ?? 'OUT_OF_STOCK') === 'IN_STOCK',
                    'loja'       => 'Pichau',
                ];
            }
        }
    }
    // BUG FIX: o fallback antigo rodava o parser de markdown da Jina sobre o
    // corpo da resposta do GraphQL (JSON), que nunca bate com o regex —
    // o resultado real era "0 produtos da Pichau" sempre que o GraphQL falhava.
    // Agora disparamos uma chamada real à Jina Reader para obter o markdown.
    if (empty($pichauData)) {
        $fallback   = buscarViaJina(
            'https://www.pichau.com.br/search?q=' . rawurlencode($query),
            'pichau',
            $query,
            30
        );
        $pichauData = $fallback['produtos'] ?? [];
    }

    // 4. Processa Mercado Livre, KaBuM, Amazon, Magazine Luiza, Terabyte Shop e lojas VTEX
    $meliData = parseMercadoLivreJson((string)($responses['mercadolivre']['body'] ?? ''), (int)($responses['mercadolivre']['status'] ?? 0));

    // Se a API oficial devolveu 429 (rate limit), tenta mais uma vez depois de
    // uma pequena espera antes de desistir — na prática o rate limit do
    // Mercado Livre costuma ser por uma janela curta, então uma segunda
    // tentativa meio segundo depois às vezes já passa, sem precisar de
    // scraping nenhum.
    if (empty($meliData['produtos']) && (int) ($responses['mercadolivre']['status'] ?? 0) === 429) {
        usleep(600000); // 0.6s
        $retryHandle = buildCurlHandle(
            'https://api.mercadolibre.com/sites/MLB/search?q=' . rawurlencode($query) . '&limit=30',
            $meliHeaders
        );
        $retryResult = curlMultiExec(['mercadolivre' => $retryHandle]);
        $meliData = parseMercadoLivreJson(
            (string) ($retryResult['mercadolivre']['body'] ?? ''),
            (int) ($retryResult['mercadolivre']['status'] ?? 0)
        );
        if (!empty($meliData['produtos'])) {
            logMsg('INFO', 'mercadolivre', 'Rate limit recuperado após nova tentativa.');
        }
    }

    // FALLBACK: quando a API oficial ainda assim não devolve nada, caímos
    // para um scraping via Jina Reader da página pública de busca — mesma
    // estratégia usada pra Pichau/KaBuM!/Amazon.
    // LIMITAÇÃO CONHECIDA (confirmada em teste real em 05/07/2026): a página
    // lista.mercadolivre.com.br costuma responder com um aviso de cookies /
    // "Ocorreu um erro, tente novamente" para acessos automatizados (Jina
    // Reader incluso), em vez do conteúdo real da busca — então este
    // fallback pode não recuperar nada na prática até se achar uma URL/
    // abordagem que escape desse muro de cookies. A prioridade real de
    // disponibilidade do Mercado Livre continua sendo a API oficial acima.
    if (empty($meliData['produtos'])) {
        $meliFallback = buscarViaJina(
            'https://lista.mercadolivre.com.br/' . rawurlencode(str_replace(' ', '-', normalizarTexto($query))),
            'mercadolivre',
            $query,
            30
        );
        if (!empty($meliFallback['produtos'])) {
            $meliData = ['produtos' => $meliFallback['produtos'], 'aviso' => 'Mercado Livre: resultados obtidos via fallback.'];
        }
    }

    $kabumData    = parseKabumMarkdown((string)($responses['kabum']['body']    ?? ''), 30);
    $amazonData   = parseAmazonMarkdown((string)($responses['amazon']['body']  ?? ''), 30);
    $magaluData   = parseMagaluMarkdown((string)($responses['magalu']['body']  ?? ''), 30);
    $terabyteData = parseTerabyteMarkdown((string)($responses['terabyte']['body'] ?? ''), 30);

    $vtexData = [];
    foreach ($vtexLojas as $loja) {
        $resp = $responses['vtex_' . $loja['id']] ?? ['body' => '', 'status' => 0];
        $vtexData[] = parseVtexJson((string) $resp['body'], (int) $resp['status'], $loja['nome']);
    }

    // 5. Junta e filtra por relevância
    $produtos = [];
    $avisos   = [];

    $listasProdutos = [
        $pichauData,
        $meliData['produtos']     ?? [],
        $kabumData['produtos']    ?? [],
        $amazonData['produtos']   ?? [],
        $magaluData['produtos']   ?? [],
        $terabyteData['produtos'] ?? [],
    ];
    foreach ($vtexData as $dado) {
        $listasProdutos[] = $dado['produtos'] ?? [];
    }

    foreach ($listasProdutos as $lista) {
        foreach ($lista as $produto) {
            if (produtoCombinaComBusca($produto, $query)) {
                $produtos[] = $produto;
            }
        }
    }

    foreach (array_merge([$meliData, $kabumData, $amazonData, $magaluData, $terabyteData], $vtexData) as $fonte) {
        if (!empty($fonte['aviso'])) $avisos[] = $fonte['aviso'];
    }

    ordenarProdutosPorPreco($produtos);
    $produtos = array_slice($produtos, 0, 36);

    $lojas = [];
    foreach ($produtos as $produto) {
        $loja          = $produto['loja'] ?? 'Loja';
        $lojas[$loja] = ($lojas[$loja] ?? 0) + 1;
    }

    // Rótulo "fonte" montado dinamicamente, incluindo as lojas VTEX cadastradas
    $nomesFontes = array_merge(
        ['Pichau', 'Mercado Livre', 'KaBuM!', 'Amazon', 'Magazine Luiza', 'Terabyte Shop'],
        array_map(fn($l) => $l['nome'], $vtexLojas)
    );
    $fonteLabel = count($nomesFontes) > 1
        ? implode(', ', array_slice($nomesFontes, 0, -1)) . ' e ' . end($nomesFontes)
        : ($nomesFontes[0] ?? '');

    return [
        'total'   => count($produtos),
        'produtos' => $produtos,
        'fonte'   => $fonteLabel,
        'lojas'   => $lojas,
        'aviso'   => $avisos ? implode(' ', array_unique($avisos)) : '',
    ];
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function ordenarProdutosPorPreco(array &$produtos): void
{
    usort($produtos, static function (array $a, array $b): int {
        $precoA = $a['preco'] ?? null;
        $precoB = $b['preco'] ?? null;
        if ($precoA === null && $precoB === null) return 0;
        if ($precoA === null) return 1;
        if ($precoB === null) return -1;
        return $precoA <=> $precoB;
    });
}

function produtoCombinaComBusca(array $produto, string $query): bool
{
    $nome        = $produto['nome'] ?? '';
    $url         = $produto['url']  ?? '';
    $textoNome   = normalizarTexto($nome);
    $textoProduto = normalizarTexto($nome . ' ' . str_replace(['-', '/', '_'], ' ', $url));
    $textoBusca  = normalizarTexto($query);

    if ($textoNome === '' || $textoBusca === '') return false;

    preg_match_all('/\b\d{3,5}\b/u', $textoBusca, $numerosBusca);
    foreach (array_unique($numerosBusca[0] ?? []) as $numero) {
        if (!preg_match('/\b' . preg_quote($numero, '/') . '\b/u', $textoNome)) return false;
    }

    if (preg_match('/\b(placa\s+de\s+video|placa\s+video|gpu|rtx|gtx|radeon|rx)\b/u', $textoBusca)) {
        if (!preg_match('/\b(placa\s+de\s+video|placa\s+video|gpu|rtx|gtx|radeon|rx|geforce)\b/u', $textoNome)) return false;
        if (preg_match('/\b(pc|computador|desktop|workstation|setup)\b/u', $textoNome)
            && !preg_match('/\b(pc|computador|desktop|workstation|setup)\b/u', $textoBusca)) return false;
    }

    $bloqueios = [
        '/\b(pc|computador|desktop|workstation)\b/u' => '/\b(pc|computador|desktop|workstation)\b/u',
        '/\b(kit|combo)\b/u'  => '/\b(kit|combo)\b/u',
        '/\b(teclado)\b/u'    => '/\b(teclado)\b/u',
        '/\b(monitor)\b/u'    => '/\b(monitor)\b/u',
    ];

    foreach ($bloqueios as $padraoProduto => $padraoBusca) {
        if (preg_match($padraoProduto, $textoNome) && !preg_match($padraoBusca, $textoBusca)) return false;
    }

    $tokensBusca_ = tokensBusca($textoBusca);
    if (!$tokensBusca_) return true;

    $tokensEncontrados = 0;
    foreach ($tokensBusca_ as $token) {
        if (str_contains($textoNome, $token)) $tokensEncontrados++;
    }

    $minimo = count($tokensBusca_) <= 2 ? count($tokensBusca_) : max(2, (int) ceil(count($tokensBusca_) * 0.7));
    return $tokensEncontrados >= $minimo;
}

function tokensBusca(string $texto): array
{
    $stopwords = ['de', 'da', 'do', 'das', 'dos', 'com', 'sem', 'para', 'por', 'um', 'uma', 'o', 'a', 'e'];
    preg_match_all('/[a-z0-9]+/u', $texto, $matches);
    return array_values(array_unique(array_filter($matches[0] ?? [], static function (string $token) use ($stopwords): bool {
        return strlen($token) >= 2 && !in_array($token, $stopwords, true);
    })));
}

function normalizarTexto(string $texto): string
{
    $texto     = mb_strtolower($texto, 'UTF-8');
    $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    $texto     = $convertido !== false ? $convertido : $texto;
    $texto     = preg_replace('/[^a-z0-9]+/u', ' ', $texto);
    return trim(preg_replace('/\s+/', ' ', $texto));
}

function moedaParaFloat(string $valor): float
{
    return (float) str_replace(',', '.', str_replace('.', '', $valor));
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
if ($action === 'search') {
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
        exit;
    }

    // Modo manutenção (Configurações → Sistema): bloqueia buscas de usuários
    // comuns, mas continua liberado para administradores testarem o sistema.
    if (getSetting('status_sistema') === 'manutencao' && !isAdmin()) {
        echo json_encode(['success' => false, 'message' => 'O sistema está em manutenção no momento. Tente novamente mais tarde.']);
        exit;
    }

    $busca = trim($_POST['busca'] ?? '');

    if (mb_strlen($busca) < 2) {
        echo json_encode(['success' => false, 'message' => 'Digite pelo menos 2 caracteres para pesquisar.']);
        exit;
    }
    if (mb_strlen($busca) > 120) {
        echo json_encode(['success' => false, 'message' => 'Termo de pesquisa muito longo.']);
        exit;
    }

    ensureSchema(getDB());
    $maxResultados = (int) getSetting('max_resultados');
    if ($maxResultados < 1) $maxResultados = 30;

    // Verifica cache
    $inicio   = microtime(true);
    $cacheKey = 'search_' . strtolower($busca);
    $cached   = cacheGet($cacheKey);

    if ($cached !== null) {
        // Cache hit: mede o tempo real desta requisição (leitura do cache),
        // em vez de reaproveitar o tempo da busca original que gerou o cache.
        $tempoCache   = round((microtime(true) - $inicio), 2);
        $tempoCacheMs = (int) round((microtime(true) - $inicio) * 1000);

        $produtosCache = $cached['data']['produtos'] ?? [];
        if (count($produtosCache) > $maxResultados) {
            $produtosCache = array_slice($produtosCache, 0, $maxResultados);
            $cached['data']['produtos'] = $produtosCache;
        }

        registrarPesquisa($busca, count($produtosCache), 'cache', $tempoCacheMs);
        echo json_encode([
            'success'  => true,
            'data'     => $cached['data'],
            'tempo'    => $tempoCache,
            'do_cache' => true,
        ]);
        exit;
    }

    $resultado = buscarProdutosMultisite($busca);
    $tempo     = round((microtime(true) - $inicio), 2);
    $tempoMs   = (int) round((microtime(true) - $inicio) * 1000);

    if (isset($resultado['erro'])) {
        logMsg('ERROR', 'scraper.search', "Busca por \"{$busca}\" falhou: {$resultado['erro']}");
        echo json_encode(['success' => false, 'message' => $resultado['erro']]);
        exit;
    }

    // Aplica o limite máximo de resultados configurado no painel admin
    if (!empty($resultado['produtos']) && count($resultado['produtos']) > $maxResultados) {
        $resultado['produtos'] = array_slice($resultado['produtos'], 0, $maxResultados);
    }

    // Salva no cache apenas se obteve resultados
    if (!empty($resultado['produtos'])) {
        cacheSet($cacheKey, ['data' => $resultado, 'tempo' => $tempo]);
    }

    registrarPesquisa($busca, count($resultado['produtos'] ?? []), 'live', $tempoMs);
    logMsg('INFO', 'scraper.search', "Busca por \"{$busca}\" concluída em {$tempo}s com " . count($resultado['produtos'] ?? []) . ' produtos.');

    echo json_encode([
        'success'  => true,
        'data'     => $resultado,
        'tempo'    => $tempo,
        'do_cache' => false,
    ]);
    exit;
}

/**
 * Grava uma pesquisa no histórico real (tabela search_log), usado
 * pelo painel administrativo. Falhas aqui nunca devem quebrar a busca.
 */
function registrarPesquisa(string $termo, int $resultados, string $origem, int $duracaoMs = 0): void
{
    try {
        $db   = getDB();
        $stmt = $db->prepare('INSERT INTO search_log (user_id, term, results_count, source, duration_ms) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'] ?? null, mb_substr($termo, 0, 120), $resultados, $origem, $duracaoMs]);
    } catch (\Throwable $e) {
        logMsg('WARNING', 'search_log', 'Não foi possível registrar o histórico de pesquisa: ' . $e->getMessage());
    }
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
