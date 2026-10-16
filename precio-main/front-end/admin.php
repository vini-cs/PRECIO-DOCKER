<?php
require_once __DIR__ . '/../back-end/includes/auth.php';
require_once __DIR__ . '/../back-end/includes/db.php';
require_once __DIR__ . '/../back-end/includes/settings.php';
require_once __DIR__ . '/../back-end/includes/migrations.php';

requireAdmin();
ensureSchema(getDB());

$user = currentUser();
$adminName = $user['name'] ?: 'Administrador';
$adminInitials = mb_strtoupper(mb_substr($adminName, 0, 1));

$config = getSettings();
$nomeSistema = $config['nome_sistema'] ?: 'Precio';
$logoSistema = $config['logo_sistema'] ?: '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nomeSistema) ?> Admin - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
<div class="admin-shell" data-sidebar="expanded">
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <a href="#dashboard" class="brand-mark" data-section-link="dashboard" aria-label="<?= htmlspecialchars($nomeSistema) ?>">
                <span class="brand-icon">
                    <?php if ($logoSistema): ?>
                        <img src="<?= htmlspecialchars($logoSistema) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">
                    <?php else: ?>
                        <i class="bi bi-compass"></i>
                    <?php endif; ?>
                </span>
                <span class="brand-copy">
                    <strong><?= htmlspecialchars($nomeSistema) ?></strong>
                    <small>Admin Suite</small>
                </span>
            </a>
            <button class="icon-btn sidebar-close d-lg-none" type="button" data-sidebar-close aria-label="Fechar menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Menu administrativo">
            <a class="nav-link active" href="#dashboard" data-section-link="dashboard"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
            <a class="nav-link" href="#produtos" data-section-link="produtos"><i class="bi bi-box-seam"></i><span>Produtos</span></a>
            <a class="nav-link" href="#categorias" data-section-link="categorias"><i class="bi bi-tags"></i><span>Categorias</span></a>
            <a class="nav-link" href="#lojas" data-section-link="lojas"><i class="bi bi-shop"></i><span>Lojas</span></a>
            <a class="nav-link" href="#historico" data-section-link="historico"><i class="bi bi-search"></i><span>Histórico de Pesquisas</span></a>
            <a class="nav-link" href="#configuracoes" data-section-link="configuracoes"><i class="bi bi-sliders"></i><span>Configurações</span></a>
            <a class="nav-link" href="#perfil" data-section-link="perfil"><i class="bi bi-person-circle"></i><span>Perfil</span></a>

            <button class="nav-link nav-group-toggle" data-bs-toggle="collapse" data-bs-target="#menuScrapers" type="button" aria-expanded="false">
                <i class="bi bi-cpu"></i><span>Sistema</span><i class="bi bi-chevron-down nav-chevron"></i>
            </button>
            <div class="collapse nav-submenu" id="menuScrapers">
                <a href="#scrapers" data-section-link="scrapers">Monitoramento de Scrapers</a>
                <a href="#cacheProdutos" data-section-link="cacheProdutos">Cache de Buscas</a>
                <a href="#logs" data-section-link="logs">Logs</a>
                <a href="#usuarios" data-section-link="usuarios">Usuários</a>
            </div>

            <a class="nav-link nav-link-danger" href="../back-end/logout.php"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
        </nav>
    </aside>

    <div class="sidebar-backdrop" data-sidebar-close></div>

    <main class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button class="icon-btn" type="button" id="sidebarToggle" aria-label="Alternar sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>
                <div class="global-search">
                    <i class="bi bi-search"></i>
                    <input type="search" id="globalSearch" placeholder="Ir para uma seção do painel..." autocomplete="off">
                    <kbd>/</kbd>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="icon-btn" type="button" id="themeToggle" aria-label="Alternar dark mode"><i class="bi bi-moon-stars"></i></button>
                <div class="admin-profile dropdown">
                    <button class="profile-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="profile-avatar"><?= htmlspecialchars($adminInitials) ?></span>
                        <span class="profile-copy">
                            <strong><?= htmlspecialchars($adminName) ?></strong>
                            <small><?= htmlspecialchars($user['email'] ?? '') ?></small>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="home.php">Voltar ao site</a>
                        <a class="dropdown-item" href="#perfil" data-section-link="perfil">Meu perfil</a>
                        <a class="dropdown-item" href="../back-end/logout.php">Sair</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="admin-content">
            <!-- DASHBOARD -->
            <section class="content-section active" id="dashboard" data-section>
                <div class="page-heading">
                    <div>
                        <span class="eyebrow">Visão geral</span>
                        <h1>Dashboard Administrativo</h1>
                        <p>Indicadores reais do banco de dados do comparador.</p>
                    </div>
                    <div class="heading-actions">
                        <button class="btn btn-light btn-sm" type="button" id="refreshDashboard"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
                    </div>
                </div>

                <div class="stats-grid" id="statsGrid">
                    <div class="empty-state">Carregando estatísticas...</div>
                </div>

                <article class="panel">
                    <div class="panel-header">
                        <div><h2>Pesquisas por Dia</h2><p>Últimos 7 dias (histórico real de buscas)</p></div>
                    </div>
                    <div class="chart-wrap"><canvas id="searchesLineChart"></canvas></div>
                </article>
            </section>

            <!-- HISTÓRICO DE PESQUISAS -->
            <section class="content-section" id="historico" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Pesquisas</span><h1>Histórico de Pesquisas</h1><p>Registro real de todas as buscas feitas no comparador.</p></div>
                </div>
                <article class="panel">
                    <div class="toolbar toolbar-3">
                        <input class="form-control" type="search" id="historicoSearch" placeholder="Buscar por termo">
                        <input class="form-control" type="date" id="historicoDataInicio" title="Data inicial">
                        <input class="form-control" type="date" id="historicoDataFim" title="Data final">
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle sortable-table" id="historicoTable">
                            <thead>
                                <tr>
                                    <th data-sort="sl.term">Termo</th>
                                    <th>Usuário</th>
                                    <th>Origem</th>
                                    <th data-sort="sl.results_count">Resultados</th>
                                    <th data-sort="sl.duration_ms">Tempo</th>
                                    <th data-sort="sl.created_at" class="sorted-desc">Data</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="historicoBody"><tr><td colspan="7" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="historicoPagination"></div>
                </article>
            </section>

            <!-- PRODUTOS (CATÁLOGO REAL) -->
            <section class="content-section" id="produtos" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Catálogo</span><h1>Produtos</h1><p>Produtos cadastrados manualmente no catálogo do comparador.</p></div>
                    <button class="btn btn-primary btn-sm" type="button" id="newCatalogProductBtn"><i class="bi bi-plus-lg"></i> Novo produto</button>
                </div>
                <article class="panel">
                    <div class="toolbar toolbar-3">
                        <input class="form-control" type="search" id="catalogoSearch" placeholder="Buscar por nome do produto">
                        <select class="form-select" id="catalogoCategoriaFiltro">
                            <option value="">Todas as categorias</option>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle sortable-table" id="catalogoTable">
                            <thead>
                                <tr>
                                    <th>Imagem</th>
                                    <th data-sort="p.nome">Nome</th>
                                    <th>Categoria</th>
                                    <th data-sort="p.ativo">Status</th>
                                    <th data-sort="p.updated_at" class="sorted-desc">Atualizado em</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="catalogoBody"><tr><td colspan="6" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="catalogoPagination"></div>
                </article>
            </section>

            <!-- CATEGORIAS -->
            <section class="content-section" id="categorias" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Catálogo</span><h1>Categorias</h1><p>Categorias usadas para organizar os produtos do catálogo.</p></div>
                    <button class="btn btn-primary btn-sm" type="button" id="newCategoriaBtn"><i class="bi bi-plus-lg"></i> Nova categoria</button>
                </div>
                <article class="panel">
                    <div class="toolbar">
                        <input class="form-control" type="search" id="categoriasSearch" placeholder="Buscar por nome ou slug">
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle sortable-table" id="categoriasTable">
                            <thead>
                                <tr>
                                    <th data-sort="c.nome" class="sorted-asc">Nome</th>
                                    <th>Slug</th>
                                    <th>Produtos</th>
                                    <th>Status</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="categoriasBody"><tr><td colspan="5" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="categoriasPagination"></div>
                </article>
            </section>

            <!-- LOJAS -->
            <section class="content-section" id="lojas" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Catálogo</span><h1>Lojas</h1><p>Lojas exibidas no site e utilizadas pelo sistema de comparação.</p></div>
                    <button class="btn btn-primary btn-sm" type="button" id="newLojaBtn"><i class="bi bi-plus-lg"></i> Nova loja</button>
                </div>
                <article class="panel">
                    <div class="toolbar">
                        <input class="form-control" type="search" id="lojasSearch" placeholder="Buscar por nome da loja">
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>Ordem</th><th>Loja</th><th>URL</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                            <tbody id="lojasBody"><tr><td colspan="5" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="lojasPagination"></div>
                </article>
            </section>

            <!-- SISTEMA: CACHE DE BUSCAS (produtos coletados pelos scrapers) -->
            <section class="content-section" id="cacheProdutos" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Sistema</span><h1>Cache de Buscas</h1><p>Produtos já coletados pelos scrapers e guardados no cache de buscas.</p></div>
                    <button class="btn btn-light btn-sm" type="button" id="clearCacheBtn"><i class="bi bi-trash3"></i> Limpar cache</button>
                </div>
                <article class="panel">
                    <div class="toolbar">
                        <input class="form-control" type="search" id="produtosSearch" placeholder="Buscar por nome do produto">
                        <select class="form-select" id="produtosLoja">
                            <option value="">Todas as lojas</option>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>Imagem</th><th>Nome</th><th>Preço</th><th>Loja</th><th>Estoque</th><th>Coletado em</th><th class="text-end">Ações</th></tr></thead>
                            <tbody id="produtosBody"><tr><td colspan="7" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="produtosPagination"></div>
                </article>
            </section>

            <!-- SCRAPERS: MONITORAMENTO -->
            <section class="content-section" id="scrapers" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Sistema</span><h1>Monitoramento de Scrapers</h1><p>Estatísticas reais calculadas a partir do cache de buscas de cada loja.</p></div>
                    <button class="btn btn-light btn-sm" type="button" id="clearCacheBtn2"><i class="bi bi-trash3"></i> Limpar cache</button>
                </div>

                <div class="stats-grid" id="scrapersStatsGrid"></div>

                <div class="dashboard-grid">
                    <article class="panel">
                        <div class="panel-header">
                            <div><h2>Produtos em Cache por Loja</h2><p>Catálogo coletado atualmente</p></div>
                        </div>
                        <div class="chart-wrap"><canvas id="storesBarChart"></canvas></div>
                    </article>
                    <article class="panel">
                        <div class="panel-header"><div><h2>Termos mais pesquisados</h2><p>Baseado no histórico real de pesquisas</p></div></div>
                        <div class="table-responsive">
                            <table class="table admin-table align-middle">
                                <thead><tr><th>Termo</th><th>Pesquisas</th></tr></thead>
                                <tbody id="topTermsBody"><tr><td colspan="2" class="text-muted">Carregando...</td></tr></tbody>
                            </table>
                        </div>
                    </article>
                </div>

                <article class="panel mt-3">
                    <div class="panel-header"><div><h2>Lojas com dados em cache</h2><p>Agregado a partir dos produtos já coletados</p></div></div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>Loja</th><th>Produtos em Cache</th><th>Em Estoque</th><th>Última Coleta</th></tr></thead>
                            <tbody id="storesBody"><tr><td colspan="4" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                </article>
            </section>

            <!-- SCRAPERS: LOGS -->
            <section class="content-section" id="logs" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Sistema</span><h1>Visualizador de Logs</h1><p>Eventos reais registrados pelo back-end (login, buscas, ações administrativas).</p></div>
                    <button class="btn btn-light btn-sm" type="button" id="clearLogsBtn"><i class="bi bi-trash3"></i> Limpar log</button>
                </div>
                <article class="panel log-viewer">
                    <div class="log-filters" role="group" aria-label="Filtros de log">
                        <?php foreach (['ALL' => 'Todos', 'INFO' => 'INFO', 'WARNING' => 'WARNING', 'ERROR' => 'ERROR', 'CRITICAL' => 'CRITICAL'] as $level => $label): ?>
                            <button class="btn btn-sm <?= $level === 'ALL' ? 'btn-primary' : 'btn-soft' ?>" type="button" data-log-filter="<?= $level ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="log-list" id="logList">
                        <div class="text-muted p-3">Carregando...</div>
                    </div>
                </article>
            </section>

            <!-- USUÁRIOS -->
            <section class="content-section" id="usuarios" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Acesso</span><h1>Gestão de Usuários</h1><p>Contas cadastradas no banco de dados, com papel de acesso (admin/usuário).</p></div>
                    <button class="btn btn-primary btn-sm" type="button" id="newUserBtn"><i class="bi bi-person-plus"></i> Novo usuário</button>
                </div>
                <article class="panel">
                    <div class="toolbar">
                        <input class="form-control" type="search" id="usersSearch" placeholder="Buscar por nome ou e-mail">
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>Nome</th><th>Email</th><th>Cadastro</th><th>Papel</th><th class="text-end">Ações</th></tr></thead>
                            <tbody id="usersBody"><tr><td colspan="5" class="text-muted">Carregando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="table-footer" id="usersPagination"></div>
                </article>
            </section>

            <!-- CONFIGURAÇÕES -->
            <section class="content-section" id="configuracoes" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Administração</span><h1>Configurações</h1><p>Parâmetros reais do sistema, salvos no banco de dados.</p></div>
                </div>
                <div class="settings-grid">
                    <article class="panel">
                        <div class="panel-header"><div><h2>Sistema</h2><p>Identidade e status geral do comparador</p></div></div>
                        <form class="form-stack" id="sistemaForm">
                            <label>Nome do sistema<input class="form-control" type="text" id="nomeSistemaInput" maxlength="60" required></label>
                            <label>URL do logo (opcional)<input class="form-control" type="url" id="logoSistemaInput" placeholder="https://exemplo.com/logo.png"></label>
                            <label>Quantidade máxima de resultados por busca<input class="form-control" type="number" id="maxResultadosInput" min="1" max="200" required></label>
                            <label>Status do sistema
                                <select class="form-select" id="statusSistemaInput">
                                    <option value="online">Online</option>
                                    <option value="manutencao">Manutenção</option>
                                </select>
                            </label>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Salvar sistema</button>
                        </form>
                    </article>
                    <article class="panel">
                        <div class="panel-header"><div><h2>Cache de Buscas</h2><p>Tempo de validade do cache usado pela busca do site</p></div></div>
                        <form class="form-stack" id="settingsForm">
                            <label>Tempo de cache (minutos)<input class="form-control" type="number" id="cacheTtlInput" min="1" max="1440" required></label>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Salvar configurações</button>
                        </form>
                    </article>
                    <article class="panel">
                        <div class="panel-header"><div><h2>Mercado Livre</h2><p>Credenciais opcionais da API oficial (aumentam o limite de requisições)</p></div></div>
                        <form class="form-stack" id="meliForm">
                            <p class="text-muted small mb-0">Sem credenciais, a busca usa o endpoint público do Mercado Livre normalmente, só que com limite de requisições menor. Crie um app em <a href="https://developers.mercadolivre.com.br" target="_blank" rel="noopener">developers.mercadolivre.com.br</a> para gerar o Client ID e o Client Secret.</p>
                            <label>Client ID<input class="form-control" type="text" id="meliClientId" placeholder="Ex: 1234567890123456" autocomplete="off"></label>
                            <label>Client Secret<input class="form-control" type="password" id="meliClientSecret" placeholder="Deixe em branco para manter o atual" autocomplete="off"></label>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Salvar credenciais</button>
                        </form>
                    </article>
                    <article class="panel panel-wide">
                        <div class="panel-header"><div><h2>Lojas VTEX</h2><p>Lojas que rodam na plataforma VTEX expõem uma API pública de catálogo (sem chave). Cadastre aqui o nome de exibição e o domínio do endpoint.</p></div></div>
                        <p class="text-muted small">Teste o domínio antes de cadastrar: abra <code>https://SEU-DOMINIO/api/catalog_system/pub/products/search?ft=teste</code> no navegador — se aparecer uma lista JSON de produtos, o domínio funciona. Tente primeiro o domínio da própria loja (ex: <code>www.centauro.com.br</code>).</p>
                        <form class="vtex-add-form" id="vtexAddForm">
                            <input class="form-control" type="text" id="vtexNome" placeholder="Nome da loja (ex: Centauro)" required>
                            <input class="form-control" type="text" id="vtexDominio" placeholder="Domínio (ex: www.centauro.com.br)" required>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Adicionar</button>
                        </form>
                        <div class="table-responsive mt-3">
                            <table class="table admin-table align-middle">
                                <thead><tr><th>Loja</th><th>Domínio</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                                <tbody id="vtexBody"><tr><td colspan="4" class="text-muted">Carregando...</td></tr></tbody>
                            </table>
                        </div>
                    </article>
                </div>
            </section>

            <!-- PERFIL -->
            <section class="content-section" id="perfil" data-section>
                <div class="page-heading compact">
                    <div><span class="eyebrow">Administração</span><h1>Perfil</h1><p>Dados da sua conta de administrador.</p></div>
                </div>
                <div class="settings-grid">
                    <article class="panel">
                        <div class="panel-header"><div><h2>Meus dados</h2><p>Nome, e-mail e senha de acesso</p></div></div>
                        <form class="form-stack" id="profileForm">
                            <label>Nome<input class="form-control" type="text" id="profileName" value="<?= htmlspecialchars($adminName) ?>" required></label>
                            <label>Email<input class="form-control" type="email" id="profileEmail" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required></label>
                            <label>Nova senha (opcional)<input class="form-control" type="password" id="profilePassword" placeholder="Deixe em branco para manter a atual"></label>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Salvar perfil</button>
                        </form>
                    </article>
                </div>
            </section>
        </div>
    </main>
</div>

<!-- MODAL: usuário (criar/editar) -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="userForm">
                <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Novo usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body form-stack">
                    <input type="hidden" id="userId">
                    <label>Nome<input class="form-control" type="text" id="userName" required></label>
                    <label>Email<input class="form-control" type="email" id="userEmail" required></label>
                    <label>Senha <span id="userPasswordHint" class="text-muted small"></span><input class="form-control" type="password" id="userPassword"></label>
                    <label>Papel
                        <select class="form-select" id="userRole">
                            <option value="user">Usuário</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </label>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: produto (editar) -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="productForm">
                <div class="modal-header"><h5 class="modal-title">Editar produto</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body form-stack">
                    <input type="hidden" id="productId">
                    <label>Nome<input class="form-control" type="text" id="productName" required></label>
                    <label>Preço (R$)<input class="form-control" type="number" step="0.01" min="0" id="productPrice" required></label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="productStock">
                        <label class="form-check-label" for="productStock">Em estoque</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: produto do catálogo (criar/editar) -->
<div class="modal fade" id="catalogProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="catalogProductForm">
                <div class="modal-header"><h5 class="modal-title" id="catalogProductModalTitle">Novo produto</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body form-stack">
                    <input type="hidden" id="catalogProductId">
                    <label>Nome<input class="form-control" type="text" id="catalogProductNome" maxlength="180" required></label>
                    <label>Categoria
                        <select class="form-select" id="catalogProductCategoria">
                            <option value="">Sem categoria</option>
                        </select>
                    </label>
                    <label>URL da imagem (opcional)<input class="form-control" type="url" id="catalogProductImagem" placeholder="https://exemplo.com/produto.png"></label>
                    <label>Descrição (opcional)<textarea class="form-control" id="catalogProductDescricao" rows="3" maxlength="2000"></textarea></label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="catalogProductAtivo" checked>
                        <label class="form-check-label" for="catalogProductAtivo">Ativo</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: categoria (criar/editar) -->
<div class="modal fade" id="categoriaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="categoriaForm">
                <div class="modal-header"><h5 class="modal-title" id="categoriaModalTitle">Nova categoria</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body form-stack">
                    <input type="hidden" id="categoriaId">
                    <label>Nome<input class="form-control" type="text" id="categoriaNome" maxlength="100" required></label>
                    <label>Slug <span class="text-muted small">(opcional — gerado a partir do nome se vazio)</span><input class="form-control" type="text" id="categoriaSlug" maxlength="120" placeholder="ex: placas-de-video"></label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="categoriaAtivo" checked>
                        <label class="form-check-label" for="categoriaAtivo">Ativa</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: loja (criar/editar) -->
<div class="modal fade" id="lojaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="lojaForm">
                <div class="modal-header"><h5 class="modal-title" id="lojaModalTitle">Nova loja</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body form-stack">
                    <input type="hidden" id="lojaId">
                    <label>Nome<input class="form-control" type="text" id="lojaNome" maxlength="120" required></label>
                    <label>URL da loja<input class="form-control" type="url" id="lojaUrl" placeholder="https://www.loja.com.br" required></label>
                    <label>URL do logo (opcional)<input class="form-control" type="url" id="lojaLogo" placeholder="https://exemplo.com/logo.png"></label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="lojaAtivo" checked>
                        <label class="form-check-label" for="lojaAtivo">Ativa</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Salvar</button></div>
            </form>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="adminToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">Ação concluída.</div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
