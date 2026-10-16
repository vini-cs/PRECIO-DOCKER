<?php
// Funções para ler e gerenciar os produtos que já foram coletados
// pelos scrapers e estão salvos no cache em disco (back-end/cache/*.json).
// Não existe uma tabela "products" no banco — o catálogo real do sistema
// é o próprio cache de buscas, então o painel admin opera sobre ele.

define('PRODUCTS_CACHE_DIR', __DIR__ . '/../cache');

function cacheFileList(): array {
    $files = @glob(PRODUCTS_CACHE_DIR . '/*.json');
    return $files ?: [];
}

/**
 * Gera um ID estável para um produto dentro do cache, usado nas ações
 * de editar/excluir. Combina o arquivo de cache e a URL do produto.
 */
function productId(string $cacheFile, string $url): string {
    return md5(basename($cacheFile) . '|' . $url);
}

/**
 * Lê todos os arquivos de cache válidos e devolve a lista completa de
 * produtos já coletados, cada um com um ID estável e a data da coleta.
 */
function loadAllCachedProducts(): array {
    $produtos = [];

    foreach (cacheFileList() as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) continue;

        $json = json_decode($raw, true);
        $lista = $json['data']['produtos'] ?? null;
        if (!is_array($lista)) continue;

        $mtime = @filemtime($file) ?: time();

        foreach ($lista as $item) {
            if (!is_array($item) || empty($item['url'])) continue;

            $produtos[] = [
                'id'         => productId($file, $item['url']),
                'cache_file' => basename($file),
                'nome'       => $item['nome'] ?? '',
                'preco'      => $item['preco'] ?? null,
                'preco_orig' => $item['preco_orig'] ?? null,
                'loja'       => $item['loja'] ?? 'Desconhecida',
                'url'        => $item['url'],
                'imagem'     => $item['imagem'] ?? '',
                'em_estoque' => (bool) ($item['em_estoque'] ?? true),
                'updated_at' => $mtime,
            ];
        }
    }

    return $produtos;
}

/**
 * Agrega os produtos em cache por loja: quantos produtos, quantas
 * buscas (arquivos de cache) contribuíram e quando foi a coleta mais recente.
 */
function aggregateStores(array $produtos): array {
    $stores = [];

    foreach ($produtos as $produto) {
        $loja = $produto['loja'];
        if (!isset($stores[$loja])) {
            $stores[$loja] = [
                'loja' => $loja,
                'produtos' => 0,
                'em_estoque' => 0,
                'ultima_coleta' => 0,
            ];
        }
        $stores[$loja]['produtos']++;
        if ($produto['em_estoque']) $stores[$loja]['em_estoque']++;
        $stores[$loja]['ultima_coleta'] = max($stores[$loja]['ultima_coleta'], $produto['updated_at']);
    }

    usort($stores, fn($a, $b) => $b['produtos'] <=> $a['produtos']);
    return array_values($stores);
}

/**
 * Localiza um produto pelo ID em todos os arquivos de cache.
 * Retorna o arquivo, o índice dentro do array e os dados decodificados.
 */
function locateProduct(string $id): ?array {
    foreach (cacheFileList() as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) continue;

        $json = json_decode($raw, true);
        $lista = $json['data']['produtos'] ?? null;
        if (!is_array($lista)) continue;

        foreach ($lista as $index => $item) {
            if (!is_array($item) || empty($item['url'])) continue;
            if (productId($file, $item['url']) === $id) {
                return ['file' => $file, 'index' => $index, 'json' => $json, 'item' => $item];
            }
        }
    }
    return null;
}

/**
 * Atualiza campos de um produto diretamente no arquivo de cache
 * (nome, preço e disponibilidade). A alteração é real e persiste
 * até a próxima coleta ou expiração do cache.
 */
function updateCachedProduct(string $id, array $fields): bool {
    $found = locateProduct($id);
    if (!$found) return false;

    $json = $found['json'];
    $index = $found['index'];

    if (array_key_exists('nome', $fields)) {
        $json['data']['produtos'][$index]['nome'] = (string) $fields['nome'];
    }
    if (array_key_exists('preco', $fields)) {
        $json['data']['produtos'][$index]['preco'] = (float) $fields['preco'];
    }
    if (array_key_exists('em_estoque', $fields)) {
        $json['data']['produtos'][$index]['em_estoque'] = (bool) $fields['em_estoque'];
    }

    return (bool) @file_put_contents($found['file'], json_encode($json, JSON_UNESCAPED_UNICODE));
}

/**
 * Remove um produto do arquivo de cache em que ele está.
 */
function deleteCachedProduct(string $id): bool {
    $found = locateProduct($id);
    if (!$found) return false;

    $json = $found['json'];
    array_splice($json['data']['produtos'], $found['index'], 1);
    $json['data']['total'] = count($json['data']['produtos']);

    return (bool) @file_put_contents($found['file'], json_encode($json, JSON_UNESCAPED_UNICODE));
}

/**
 * Apaga todo o cache de buscas, forçando o próximo pedido de cada
 * termo a buscar novamente nas lojas.
 */
function clearAllCache(): int {
    $count = 0;
    foreach (cacheFileList() as $file) {
        if (@unlink($file)) $count++;
    }
    return $count;
}

function cacheDiskStats(): array {
    $files = cacheFileList();
    $size = 0;
    $oldest = null;
    $newest = null;

    foreach ($files as $file) {
        $size += @filesize($file) ?: 0;
        $mtime = @filemtime($file) ?: null;
        if ($mtime === null) continue;
        if ($oldest === null || $mtime < $oldest) $oldest = $mtime;
        if ($newest === null || $mtime > $newest) $newest = $mtime;
    }

    return [
        'arquivos'      => count($files),
        'tamanho_bytes' => $size,
        'mais_antigo'   => $oldest,
        'mais_recente'  => $newest,
    ];
}
