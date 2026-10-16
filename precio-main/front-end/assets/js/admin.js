(function () {
    'use strict';

    const shell = document.querySelector('.admin-shell');
    const root = document.documentElement;
    const charts = {};
    const API = '../back-end/admin_api.php';

    // ── HELPERS ──────────────────────────────────────────────────────────────
    function cssVar(name) {
        return getComputedStyle(root).getPropertyValue(name).trim();
    }

    function showToast(message, isError) {
        const toastEl = document.getElementById('adminToast');
        if (!toastEl || !window.bootstrap) return;
        toastEl.querySelector('.toast-body').textContent = message || 'Ação concluída.';
        toastEl.classList.toggle('text-bg-danger', !!isError);
        bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3200 }).show();
    }

    function fmtBRL(value) {
        const n = Number(value);
        if (Number.isNaN(n)) return '—';
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function fmtDate(value) {
        if (!value) return '—';
        const isTimestamp = typeof value === 'number' || /^\d+$/.test(value);
        const date = isTimestamp ? new Date(Number(value) * 1000) : new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function fmtDuration(ms) {
        const n = Number(ms);
        if (!Number.isFinite(n) || n <= 0) return '—';
        if (n < 1000) return `${Math.round(n)} ms`;
        return `${(n / 1000).toFixed(2)} s`;
    }

    /**
     * Liga o clique nos cabeçalhos [data-sort] de uma tabela a um estado de
     * ordenação (sort/dir), alternando asc/desc e recarregando os dados.
     * Usado por Produtos, Categorias e Histórico de Pesquisas.
     */
    function initSortableTable(tableId, state, defaultSort, onChange) {
        const table = document.getElementById(tableId);
        if (!table) return;
        state.sort = state.sort || defaultSort.sort;
        state.dir = state.dir || defaultSort.dir;

        table.querySelectorAll('thead th[data-sort]').forEach((th) => {
            th.addEventListener('click', () => {
                const column = th.dataset.sort;
                state.dir = (state.sort === column && state.dir === 'ASC') ? 'DESC' : 'ASC';
                state.sort = column;
                state.page = 1;
                table.querySelectorAll('thead th[data-sort]').forEach((el) => el.classList.remove('sorted-asc', 'sorted-desc'));
                th.classList.add(state.dir === 'ASC' ? 'sorted-asc' : 'sorted-desc');
                onChange();
            });
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    async function callApi(action, params) {
        const body = new URLSearchParams({ action, ...(params || {}) });
        try {
            const res = await fetch(API, { method: 'POST', body });
            if (res.status === 401 || res.status === 403) {
                showToast('Sessão expirada ou acesso negado. Recarregando...', true);
                setTimeout(() => window.location.reload(), 1500);
                return { success: false };
            }
            return await res.json();
        } catch (err) {
            showToast('Falha de conexão com o servidor.', true);
            return { success: false, message: 'Falha de conexão.' };
        }
    }

    function renderPagination(container, state, totalPages, totalItems, onChange) {
        if (!container) return;
        container.innerHTML = '';

        const summary = document.createElement('span');
        summary.className = 'me-auto text-muted small align-self-center';
        summary.textContent = `${totalItems} registro(s)`;
        container.appendChild(summary);

        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-sm ${page === state.page ? 'btn-primary' : 'btn-soft'}`;
            button.textContent = page;
            button.addEventListener('click', () => {
                state.page = page;
                onChange();
            });
            container.appendChild(button);
        }
    }

    function debounce(fn, delay) {
        let timer = null;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }

    // ── NAVIGATION ───────────────────────────────────────────────────────────
    const sectionLoaders = {};

    function setSection(sectionId) {
        const fallback = document.getElementById(sectionId) ? sectionId : 'dashboard';

        document.querySelectorAll('[data-section]').forEach((section) => {
            section.classList.toggle('active', section.id === fallback);
        });
        document.querySelectorAll('[data-section-link]').forEach((link) => {
            link.classList.toggle('active', link.dataset.sectionLink === fallback);
        });

        if (history.replaceState) history.replaceState(null, '', '#' + fallback);
        shell?.classList.remove('sidebar-open');

        if (sectionLoaders[fallback]) sectionLoaders[fallback]();
        requestAnimationFrame(() => Object.values(charts).forEach((c) => c.resize()));
    }

    function initNavigation() {
        document.querySelectorAll('[data-section-link]').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                setSection(link.dataset.sectionLink);
            });
        });
        setSection(window.location.hash ? window.location.hash.slice(1) : 'dashboard');
    }

    function initSidebar() {
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            if (window.matchMedia('(max-width: 991px)').matches) {
                shell?.classList.toggle('sidebar-open');
                return;
            }
            const current = shell?.dataset.sidebar || 'expanded';
            shell.dataset.sidebar = current === 'expanded' ? 'collapsed' : 'expanded';
        });
        document.querySelectorAll('[data-sidebar-close]').forEach((el) => {
            el.addEventListener('click', () => shell?.classList.remove('sidebar-open'));
        });
    }

    function initTheme() {
        const stored = localStorage.getItem('precio-admin-theme');
        const preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        const theme = stored || preferred;
        root.dataset.theme = theme;
        updateThemeButton(theme);

        document.getElementById('themeToggle')?.addEventListener('click', () => {
            const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            localStorage.setItem('precio-admin-theme', next);
            updateThemeButton(next);
            Object.values(charts).forEach((c) => c.destroy());
            loadDashboard(true);
        });
    }

    function updateThemeButton(theme) {
        const icon = document.querySelector('#themeToggle i');
        if (icon) icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    }

    function initGlobalSearch() {
        const input = document.getElementById('globalSearch');
        if (!input) return;
        document.addEventListener('keydown', (event) => {
            if (event.key === '/' && document.activeElement !== input) {
                event.preventDefault();
                input.focus();
            }
        });
        input.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const query = input.value.trim().toLowerCase();
            if (!query) return;
            const target = Array.from(document.querySelectorAll('[data-section]')).find((s) => s.textContent.toLowerCase().includes(query));
            if (target) {
                setSection(target.id);
                showToast(`Seção encontrada: ${target.querySelector('h1')?.textContent || target.id}.`);
            } else {
                showToast('Nenhuma seção encontrada.');
            }
        });
    }

    // ── CHARTS ───────────────────────────────────────────────────────────────
    function chartColors() {
        return {
            text: cssVar('--admin-muted'),
            grid: cssVar('--admin-border'),
            primary: cssVar('--admin-primary'),
            success: cssVar('--admin-success'),
        };
    }

    function baseChartOptions(extra) {
        const colors = chartColors();
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { labels: { color: colors.text, boxWidth: 12, usePointStyle: true } },
                tooltip: { backgroundColor: '#0f172a', padding: 12, titleFont: { weight: '700' } }
            },
            scales: {
                x: { ticks: { color: colors.text }, grid: { color: colors.grid } },
                y: { ticks: { color: colors.text }, grid: { color: colors.grid }, beginAtZero: true }
            }
        }, extra || {});
    }

    function upsertChart(id, factory) {
        const canvas = document.getElementById(id);
        if (!canvas || !window.Chart) return;
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(canvas, factory());
    }

    // ── DASHBOARD ────────────────────────────────────────────────────────────
    async function loadDashboard() {
        const grid = document.getElementById('statsGrid');
        const resp = await callApi('stats');
        if (!resp.success) {
            grid.innerHTML = '<div class="empty-state">Não foi possível carregar as estatísticas.</div>';
            return;
        }
        const d = resp.data;
        const ultimaBusca = d.ultima_busca_termo
            ? `"${d.ultima_busca_termo}" — ${fmtDate(d.ultima_busca_data)}`
            : 'Nenhuma ainda';

        const cards = [
            ['Total de Buscas Realizadas', d.buscas_total, 'bi-search'],
            ['Lojas Cadastradas', d.total_lojas, 'bi-shop'],
            ['Produtos Monitorados', d.total_produtos, 'bi-box-seam'],
            ['Tempo Médio das Buscas', fmtDuration(d.tempo_medio_ms), 'bi-stopwatch'],
            ['Última Busca Realizada', ultimaBusca, 'bi-clock-history'],
        ];
        grid.innerHTML = cards.map(([label, value, icon]) => `
            <article class="metric-card">
                <div class="metric-icon"><i class="bi ${icon}"></i></div>
                <div><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>
            </article>
        `).join('');

        upsertChart('searchesLineChart', () => ({
            type: 'line',
            data: {
                labels: d.chart_buscas.labels,
                datasets: [{
                    label: 'Pesquisas', data: d.chart_buscas.values,
                    borderColor: chartColors().primary, backgroundColor: 'rgba(37, 99, 235, .12)',
                    borderWidth: 3, fill: true, tension: .38, pointRadius: 4,
                }]
            },
            options: baseChartOptions()
        }));
    }

    // ── HISTÓRICO ────────────────────────────────────────────────────────────
    const historicoState = { page: 1, q: '', data_inicio: '', data_fim: '', sort: 'sl.created_at', dir: 'DESC' };

    async function loadHistorico() {
        const body = document.getElementById('historicoBody');
        body.innerHTML = '<tr><td colspan="7" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('searches_list', {
            page: historicoState.page, q: historicoState.q,
            data_inicio: historicoState.data_inicio, data_fim: historicoState.data_fim,
            sort: historicoState.sort, dir: historicoState.dir,
        });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="7" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((row) => `
            <tr>
                <td><strong>${escapeHtml(row.term)}</strong></td>
                <td>${escapeHtml(row.user_name || 'Anônimo')}</td>
                <td><span class="status-badge status-${row.source === 'cache' ? 'neutral' : 'success'}"><i></i>${row.source === 'cache' ? 'Cache' : 'Ao vivo'}</span></td>
                <td>${escapeHtml(String(row.results_count))}</td>
                <td>${fmtDuration(row.duration_ms)}</td>
                <td>${fmtDate(row.created_at)}</td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft-danger" data-delete-historico="${row.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="7" class="text-muted">Nenhuma pesquisa encontrada.</td></tr>';

        renderPagination(document.getElementById('historicoPagination'), historicoState, resp.total_pages, resp.total, loadHistorico);
    }

    function initHistorico() {
        initSortableTable('historicoTable', historicoState, { sort: 'sl.created_at', dir: 'DESC' }, loadHistorico);

        document.getElementById('historicoSearch')?.addEventListener('input', debounce((e) => {
            historicoState.q = e.target.value.trim();
            historicoState.page = 1;
            loadHistorico();
        }, 350));

        document.getElementById('historicoDataInicio')?.addEventListener('change', (e) => {
            historicoState.data_inicio = e.target.value;
            historicoState.page = 1;
            loadHistorico();
        });

        document.getElementById('historicoDataFim')?.addEventListener('change', (e) => {
            historicoState.data_fim = e.target.value;
            historicoState.page = 1;
            loadHistorico();
        });

        document.getElementById('historicoBody')?.addEventListener('click', (event) => {
            const delBtn = event.target.closest('[data-delete-historico]');
            if (!delBtn) return;
            if (!confirm('Excluir este registro do histórico permanentemente?')) return;
            callApi('searches_delete', { id: delBtn.dataset.deleteHistorico }).then((resp) => {
                showToast(resp.message, !resp.success);
                if (resp.success) loadHistorico();
            });
        });
    }

    // ── PRODUTOS ─────────────────────────────────────────────────────────────
    const produtosState = { page: 1, q: '', loja: '' };
    let produtosStoresLoaded = false;

    async function loadProdutosStores() {
        if (produtosStoresLoaded) return;
        const resp = await callApi('stores_list');
        if (!resp.success) return;
        const select = document.getElementById('produtosLoja');
        resp.data.forEach((s) => {
            const opt = document.createElement('option');
            opt.value = s.loja;
            opt.textContent = s.loja;
            select.appendChild(opt);
        });
        produtosStoresLoaded = true;
    }

    async function loadProdutos() {
        await loadProdutosStores();
        const body = document.getElementById('produtosBody');
        body.innerHTML = '<tr><td colspan="7" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('products_list', { page: produtosState.page, q: produtosState.q, loja: produtosState.loja });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="7" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((p) => `
            <tr>
                <td><img class="product-thumb" src="${escapeHtml(p.imagem)}" alt="" onerror="this.style.visibility='hidden'"></td>
                <td><a href="${escapeHtml(p.url)}" target="_blank" rel="noopener"><strong>${escapeHtml(p.nome)}</strong></a></td>
                <td>${fmtBRL(p.preco)}</td>
                <td>${escapeHtml(p.loja)}</td>
                <td><span class="status-badge status-${p.em_estoque ? 'success' : 'danger'}"><i></i>${p.em_estoque ? 'Em estoque' : 'Esgotado'}</span></td>
                <td>${fmtDate(p.updated_at)}</td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-edit-product='${JSON.stringify(p).replace(/'/g, '&apos;')}'><i class="bi bi-pencil"></i> Editar</button>
                    <button class="btn btn-sm btn-soft-danger" data-delete-product="${p.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="7" class="text-muted">Nenhum produto em cache. Faça uma busca no site para popular o catálogo.</td></tr>';

        renderPagination(document.getElementById('produtosPagination'), produtosState, resp.total_pages, resp.total, loadProdutos);
    }

    function initProdutos() {
        document.getElementById('produtosSearch')?.addEventListener('input', debounce((e) => {
            produtosState.q = e.target.value.trim();
            produtosState.page = 1;
            loadProdutos();
        }, 350));
        document.getElementById('produtosLoja')?.addEventListener('change', (e) => {
            produtosState.loja = e.target.value;
            produtosState.page = 1;
            loadProdutos();
        });

        document.getElementById('produtosBody')?.addEventListener('click', (event) => {
            const editBtn = event.target.closest('[data-edit-product]');
            if (editBtn) {
                const p = JSON.parse(editBtn.dataset.editProduct.replace(/&apos;/g, "'"));
                document.getElementById('productId').value = p.id;
                document.getElementById('productName').value = p.nome;
                document.getElementById('productPrice').value = p.preco;
                document.getElementById('productStock').checked = !!p.em_estoque;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('productModal')).show();
                return;
            }
            const delBtn = event.target.closest('[data-delete-product]');
            if (delBtn) {
                if (!confirm('Remover este produto do cache?')) return;
                callApi('products_delete', { id: delBtn.dataset.deleteProduct }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadProdutos();
                });
            }
        });

        document.getElementById('productForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const resp = await callApi('products_update', {
                id: document.getElementById('productId').value,
                nome: document.getElementById('productName').value,
                preco: document.getElementById('productPrice').value,
                em_estoque: document.getElementById('productStock').checked ? '1' : '0',
            });
            showToast(resp.message, !resp.success);
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('productModal'))?.hide();
                loadProdutos();
            }
        });

        async function clearCache() {
            if (!confirm('Isso apaga todo o cache de buscas. As próximas pesquisas serão refeitas nas lojas. Continuar?')) return;
            const resp = await callApi('cache_clear');
            showToast(resp.message, !resp.success);
            if (resp.success) {
                loadProdutos();
                loadScrapers();
                loadDashboard();
            }
        }
        document.getElementById('clearCacheBtn')?.addEventListener('click', clearCache);
        document.getElementById('clearCacheBtn2')?.addEventListener('click', clearCache);
    }

    // ── CATEGORIAS ───────────────────────────────────────────────────────────
    const categoriasState = { page: 1, q: '', sort: 'c.nome', dir: 'ASC' };
    let categoriaOptionsCache = null;

    async function loadCategorias() {
        const body = document.getElementById('categoriasBody');
        body.innerHTML = '<tr><td colspan="5" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('categorias_list', {
            page: categoriasState.page, q: categoriasState.q,
            sort: categoriasState.sort, dir: categoriasState.dir,
        });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="5" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((c) => `
            <tr>
                <td><strong>${escapeHtml(c.nome)}</strong></td>
                <td class="text-muted">${escapeHtml(c.slug)}</td>
                <td>${escapeHtml(String(c.total_produtos))}</td>
                <td><span class="status-badge status-${c.ativo ? 'success' : 'neutral'}"><i></i>${c.ativo ? 'Ativa' : 'Inativa'}</span></td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-edit-categoria='${JSON.stringify(c).replace(/'/g, '&apos;')}'><i class="bi bi-pencil"></i> Editar</button>
                    <button class="btn btn-sm btn-soft-danger" data-delete-categoria="${c.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-muted">Nenhuma categoria cadastrada ainda.</td></tr>';

        renderPagination(document.getElementById('categoriasPagination'), categoriasState, resp.total_pages, resp.total, loadCategorias);
        categoriaOptionsCache = null;
        populateCategoriaOptions();
    }

    async function populateCategoriaOptions() {
        if (!categoriaOptionsCache) {
            const resp = await callApi('categorias_options');
            categoriaOptionsCache = resp.success ? resp.data : [];
        }
        const filtro = document.getElementById('catalogoCategoriaFiltro');
        const formSelect = document.getElementById('catalogProductCategoria');
        if (filtro) {
            const atual = filtro.value;
            filtro.innerHTML = '<option value="">Todas as categorias</option>' + categoriaOptionsCache.map((c) => `<option value="${c.id}">${escapeHtml(c.nome)}</option>`).join('');
            filtro.value = atual;
        }
        if (formSelect) {
            formSelect.innerHTML = '<option value="">Sem categoria</option>' + categoriaOptionsCache.map((c) => `<option value="${c.id}">${escapeHtml(c.nome)}</option>`).join('');
        }
        return categoriaOptionsCache;
    }

    function openCategoriaModal(categoria) {
        document.getElementById('categoriaModalTitle').textContent = categoria ? 'Editar categoria' : 'Nova categoria';
        document.getElementById('categoriaId').value = categoria ? categoria.id : '';
        document.getElementById('categoriaNome').value = categoria ? categoria.nome : '';
        document.getElementById('categoriaSlug').value = categoria ? categoria.slug : '';
        document.getElementById('categoriaAtivo').checked = categoria ? !!Number(categoria.ativo) : true;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoriaModal')).show();
    }

    function initCategorias() {
        initSortableTable('categoriasTable', categoriasState, { sort: 'c.nome', dir: 'ASC' }, loadCategorias);

        document.getElementById('categoriasSearch')?.addEventListener('input', debounce((e) => {
            categoriasState.q = e.target.value.trim();
            categoriasState.page = 1;
            loadCategorias();
        }, 350));

        document.getElementById('newCategoriaBtn')?.addEventListener('click', () => openCategoriaModal(null));

        document.getElementById('categoriasBody')?.addEventListener('click', (event) => {
            const editBtn = event.target.closest('[data-edit-categoria]');
            if (editBtn) {
                openCategoriaModal(JSON.parse(editBtn.dataset.editCategoria.replace(/&apos;/g, "'")));
                return;
            }
            const delBtn = event.target.closest('[data-delete-categoria]');
            if (delBtn) {
                if (!confirm('Excluir esta categoria permanentemente?')) return;
                callApi('categorias_delete', { id: delBtn.dataset.deleteCategoria }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadCategorias();
                });
            }
        });

        document.getElementById('categoriaForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const id = document.getElementById('categoriaId').value;
            const payload = {
                nome: document.getElementById('categoriaNome').value,
                slug: document.getElementById('categoriaSlug').value,
                ativo: document.getElementById('categoriaAtivo').checked ? '1' : '0',
            };
            const resp = id
                ? await callApi('categorias_update', { id, ...payload })
                : await callApi('categorias_create', payload);

            showToast(resp.message, !resp.success);
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('categoriaModal'))?.hide();
                loadCategorias();
            }
        });
    }

    // ── LOJAS (cadastro real, usado no site e no scraping) ──────────────────
    const lojasState = { page: 1, q: '' };

    async function loadLojas() {
        const body = document.getElementById('lojasBody');
        body.innerHTML = '<tr><td colspan="5" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('lojas_list', { page: lojasState.page, q: lojasState.q });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="5" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((l, idx) => `
            <tr>
                <td>
                    <div class="order-controls">
                        <button type="button" data-move-loja-up="${l.id}" ${idx === 0 ? 'disabled' : ''} title="Mover para cima"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" data-move-loja-down="${l.id}" ${idx === resp.items.length - 1 ? 'disabled' : ''} title="Mover para baixo"><i class="bi bi-arrow-down"></i></button>
                    </div>
                </td>
                <td>${l.logo ? `<img class="product-thumb" src="${escapeHtml(l.logo)}" alt="" onerror="this.style.visibility='hidden'"> ` : ''}<strong>${escapeHtml(l.nome)}</strong></td>
                <td><a href="${escapeHtml(l.url)}" target="_blank" rel="noopener" class="text-muted">${escapeHtml(l.url)}</a></td>
                <td><span class="status-badge status-${l.ativo ? 'success' : 'neutral'}"><i></i>${l.ativo ? 'Ativa' : 'Inativa'}</span></td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-toggle-loja="${l.id}">${l.ativo ? 'Desativar' : 'Ativar'}</button>
                    <button class="btn btn-sm btn-soft" data-edit-loja='${JSON.stringify(l).replace(/'/g, '&apos;')}'><i class="bi bi-pencil"></i> Editar</button>
                    <button class="btn btn-sm btn-soft-danger" data-delete-loja="${l.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-muted">Nenhuma loja cadastrada ainda.</td></tr>';

        renderPagination(document.getElementById('lojasPagination'), lojasState, resp.total_pages, resp.total, loadLojas);
    }

    function openLojaModal(loja) {
        document.getElementById('lojaModalTitle').textContent = loja ? 'Editar loja' : 'Nova loja';
        document.getElementById('lojaId').value = loja ? loja.id : '';
        document.getElementById('lojaNome').value = loja ? loja.nome : '';
        document.getElementById('lojaUrl').value = loja ? loja.url : '';
        document.getElementById('lojaLogo').value = loja ? (loja.logo || '') : '';
        document.getElementById('lojaAtivo').checked = loja ? !!Number(loja.ativo) : true;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('lojaModal')).show();
    }

    function initLojas() {
        document.getElementById('lojasSearch')?.addEventListener('input', debounce((e) => {
            lojasState.q = e.target.value.trim();
            lojasState.page = 1;
            loadLojas();
        }, 350));

        document.getElementById('newLojaBtn')?.addEventListener('click', () => openLojaModal(null));

        document.getElementById('lojasBody')?.addEventListener('click', (event) => {
            const editBtn = event.target.closest('[data-edit-loja]');
            if (editBtn) {
                openLojaModal(JSON.parse(editBtn.dataset.editLoja.replace(/&apos;/g, "'")));
                return;
            }
            const delBtn = event.target.closest('[data-delete-loja]');
            if (delBtn) {
                if (!confirm('Excluir esta loja permanentemente?')) return;
                callApi('lojas_delete', { id: delBtn.dataset.deleteLoja }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadLojas();
                });
                return;
            }
            const toggleBtn = event.target.closest('[data-toggle-loja]');
            if (toggleBtn) {
                callApi('lojas_toggle', { id: toggleBtn.dataset.toggleLoja }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadLojas();
                });
                return;
            }
            const upBtn = event.target.closest('[data-move-loja-up]');
            if (upBtn) {
                callApi('lojas_move', { id: upBtn.dataset.moveLojaUp, direcao: 'up' }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadLojas();
                });
                return;
            }
            const downBtn = event.target.closest('[data-move-loja-down]');
            if (downBtn) {
                callApi('lojas_move', { id: downBtn.dataset.moveLojaDown, direcao: 'down' }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadLojas();
                });
            }
        });

        document.getElementById('lojaForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const id = document.getElementById('lojaId').value;
            const payload = {
                nome: document.getElementById('lojaNome').value,
                url: document.getElementById('lojaUrl').value,
                logo: document.getElementById('lojaLogo').value,
                ativo: document.getElementById('lojaAtivo').checked ? '1' : '0',
            };
            const resp = id
                ? await callApi('lojas_update', { id, ...payload })
                : await callApi('lojas_create', payload);

            showToast(resp.message, !resp.success);
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('lojaModal'))?.hide();
                loadLojas();
            }
        });
    }

    // ── PRODUTOS (catálogo real em banco de dados) ──────────────────────────
    const catalogoState = { page: 1, q: '', categoria_id: '', sort: 'p.created_at', dir: 'DESC' };

    async function loadCatalogo() {
        await populateCategoriaOptions();
        const body = document.getElementById('catalogoBody');
        body.innerHTML = '<tr><td colspan="6" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('catalogo_list', {
            page: catalogoState.page, q: catalogoState.q, categoria_id: catalogoState.categoria_id,
            sort: catalogoState.sort, dir: catalogoState.dir,
        });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="6" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((p) => `
            <tr>
                <td>${p.imagem ? `<img class="product-thumb" src="${escapeHtml(p.imagem)}" alt="" onerror="this.style.visibility='hidden'">` : '<span class="text-muted">—</span>'}</td>
                <td><strong>${escapeHtml(p.nome)}</strong></td>
                <td>${p.categoria_nome ? escapeHtml(p.categoria_nome) : '<span class="text-muted">Sem categoria</span>'}</td>
                <td><span class="status-badge status-${p.ativo ? 'success' : 'neutral'}"><i></i>${p.ativo ? 'Ativo' : 'Inativo'}</span></td>
                <td>${fmtDate(p.updated_at)}</td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-edit-catalog='${JSON.stringify(p).replace(/'/g, '&apos;')}'><i class="bi bi-pencil"></i> Editar</button>
                    <button class="btn btn-sm btn-soft-danger" data-delete-catalog="${p.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted">Nenhum produto cadastrado ainda.</td></tr>';

        renderPagination(document.getElementById('catalogoPagination'), catalogoState, resp.total_pages, resp.total, loadCatalogo);
    }

    function openCatalogProductModal(produto) {
        document.getElementById('catalogProductModalTitle').textContent = produto ? 'Editar produto' : 'Novo produto';
        document.getElementById('catalogProductId').value = produto ? produto.id : '';
        document.getElementById('catalogProductNome').value = produto ? produto.nome : '';
        document.getElementById('catalogProductCategoria').value = produto && produto.categoria_id ? produto.categoria_id : '';
        document.getElementById('catalogProductImagem').value = produto ? (produto.imagem || '') : '';
        document.getElementById('catalogProductDescricao').value = produto ? (produto.descricao || '') : '';
        document.getElementById('catalogProductAtivo').checked = produto ? !!Number(produto.ativo) : true;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('catalogProductModal')).show();
    }

    function initCatalogo() {
        initSortableTable('catalogoTable', catalogoState, { sort: 'p.created_at', dir: 'DESC' }, loadCatalogo);

        document.getElementById('catalogoSearch')?.addEventListener('input', debounce((e) => {
            catalogoState.q = e.target.value.trim();
            catalogoState.page = 1;
            loadCatalogo();
        }, 350));

        document.getElementById('catalogoCategoriaFiltro')?.addEventListener('change', (e) => {
            catalogoState.categoria_id = e.target.value;
            catalogoState.page = 1;
            loadCatalogo();
        });

        document.getElementById('newCatalogProductBtn')?.addEventListener('click', async () => {
            await populateCategoriaOptions();
            openCatalogProductModal(null);
        });

        document.getElementById('catalogoBody')?.addEventListener('click', async (event) => {
            const editBtn = event.target.closest('[data-edit-catalog]');
            if (editBtn) {
                await populateCategoriaOptions();
                openCatalogProductModal(JSON.parse(editBtn.dataset.editCatalog.replace(/&apos;/g, "'")));
                return;
            }
            const delBtn = event.target.closest('[data-delete-catalog]');
            if (delBtn) {
                if (!confirm('Excluir este produto permanentemente?')) return;
                callApi('catalogo_delete', { id: delBtn.dataset.deleteCatalog }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadCatalogo();
                });
            }
        });

        document.getElementById('catalogProductForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const id = document.getElementById('catalogProductId').value;
            const payload = {
                nome: document.getElementById('catalogProductNome').value,
                categoria_id: document.getElementById('catalogProductCategoria').value,
                imagem: document.getElementById('catalogProductImagem').value,
                descricao: document.getElementById('catalogProductDescricao').value,
                ativo: document.getElementById('catalogProductAtivo').checked ? '1' : '0',
            };
            const resp = id
                ? await callApi('catalogo_update', { id, ...payload })
                : await callApi('catalogo_create', payload);

            showToast(resp.message, !resp.success);
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('catalogProductModal'))?.hide();
                loadCatalogo();
            }
        });
    }

    // ── SCRAPERS (MONITORAMENTO) ────────────────────────────────────────────
    async function loadScrapers() {
        const body = document.getElementById('storesBody');
        body.innerHTML = '<tr><td colspan="4" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('stores_list');
        if (!resp.success) { body.innerHTML = '<tr><td colspan="4" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.data.length ? resp.data.map((s) => `
            <tr>
                <td><strong>${escapeHtml(s.loja)}</strong></td>
                <td>${escapeHtml(String(s.produtos))}</td>
                <td>${escapeHtml(String(s.em_estoque))}</td>
                <td>${fmtDate(s.ultima_coleta)}</td>
            </tr>`).join('') : '<tr><td colspan="4" class="text-muted">Nenhum dado de coleta ainda. Faça uma busca no site.</td></tr>';

        const overview = await callApi('scrapers_overview');
        if (!overview.success) return;
        const d = overview.data;

        const grid = document.getElementById('scrapersStatsGrid');
        if (grid) {
            grid.innerHTML = [
                ['Arquivos de Cache', d.cache_arquivos, 'bi-hdd-stack'],
                ['Tamanho do Cache', `${d.cache_tamanho_kb} KB`, 'bi-database'],
            ].map(([label, value, icon]) => `
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi ${icon}"></i></div>
                    <div><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>
                </article>
            `).join('');
        }

        upsertChart('storesBarChart', () => ({
            type: 'bar',
            data: {
                labels: d.chart_lojas.labels,
                datasets: [{ label: 'Produtos', data: d.chart_lojas.values, backgroundColor: chartColors().success, borderRadius: 7 }]
            },
            options: baseChartOptions({ plugins: { legend: { display: false } } })
        }));

        const topBody = document.getElementById('topTermsBody');
        if (topBody) {
            topBody.innerHTML = d.top_termos.length
                ? d.top_termos.map((t) => `<tr><td><strong>${escapeHtml(t.term)}</strong></td><td>${escapeHtml(String(t.total))}</td></tr>`).join('')
                : '<tr><td colspan="2" class="text-muted">Nenhuma pesquisa registrada ainda.</td></tr>';
        }
    }

    // ── LOGS ─────────────────────────────────────────────────────────────────
    let currentLogLevel = 'ALL';

    async function loadLogs() {
        const list = document.getElementById('logList');
        list.innerHTML = '<div class="text-muted p-3">Carregando...</div>';
        const resp = await callApi('logs_list', { level: currentLogLevel });
        if (!resp.success) { list.innerHTML = '<div class="text-danger p-3">Erro ao carregar logs.</div>'; return; }

        list.innerHTML = resp.data.length ? resp.data.map((log) => `
            <div class="log-row" data-level="${escapeHtml(log.level)}">
                <span class="log-level level-${log.level === 'ERROR' || log.level === 'CRITICAL' ? 'danger' : log.level === 'WARNING' ? 'warning' : 'success'}">${escapeHtml(log.level)}</span>
                <span>${escapeHtml(log.date)}</span>
                <strong>${escapeHtml(log.source)}</strong>
                <p>${escapeHtml(log.message)}</p>
            </div>`).join('') : '<div class="text-muted p-3">Nenhum evento registrado ainda.</div>';
    }

    function initLogs() {
        document.querySelectorAll('[data-log-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                currentLogLevel = button.dataset.logFilter;
                document.querySelectorAll('[data-log-filter]').forEach((item) => {
                    item.classList.toggle('btn-primary', item === button);
                    item.classList.toggle('btn-soft', item !== button);
                });
                loadLogs();
            });
        });
        document.getElementById('clearLogsBtn')?.addEventListener('click', async () => {
            if (!confirm('Limpar todo o log do sistema?')) return;
            const resp = await callApi('logs_clear');
            showToast(resp.message, !resp.success);
            if (resp.success) loadLogs();
        });
    }

    // ── USUÁRIOS ─────────────────────────────────────────────────────────────
    const usersState = { page: 1, q: '' };

    async function loadUsers() {
        const body = document.getElementById('usersBody');
        body.innerHTML = '<tr><td colspan="5" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('users_list', { page: usersState.page, q: usersState.q });
        if (!resp.success) { body.innerHTML = '<tr><td colspan="5" class="text-danger">Erro ao carregar.</td></tr>'; return; }

        body.innerHTML = resp.items.length ? resp.items.map((u) => `
            <tr>
                <td><strong>${escapeHtml(u.name)}</strong></td>
                <td>${escapeHtml(u.email)}</td>
                <td>${fmtDate(u.created_at)}</td>
                <td><span class="status-badge status-${u.role === 'admin' ? 'success' : 'neutral'}"><i></i>${u.role === 'admin' ? 'Administrador' : 'Usuário'}</span></td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-edit-user='${JSON.stringify(u).replace(/'/g, '&apos;')}'><i class="bi bi-pencil"></i> Editar</button>
                    <button class="btn btn-sm btn-soft-danger" data-delete-user="${u.id}"><i class="bi bi-trash"></i> Excluir</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-muted">Nenhum usuário encontrado.</td></tr>';

        renderPagination(document.getElementById('usersPagination'), usersState, resp.total_pages, resp.total, loadUsers);
    }

    function openUserModal(user) {
        document.getElementById('userModalTitle').textContent = user ? 'Editar usuário' : 'Novo usuário';
        document.getElementById('userId').value = user ? user.id : '';
        document.getElementById('userName').value = user ? user.name : '';
        document.getElementById('userEmail').value = user ? user.email : '';
        document.getElementById('userPassword').value = '';
        document.getElementById('userPassword').required = !user;
        document.getElementById('userPasswordHint').textContent = user ? '(deixe em branco para manter a atual)' : '';
        document.getElementById('userRole').value = user ? user.role : 'user';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
    }

    function initUsers() {
        document.getElementById('usersSearch')?.addEventListener('input', debounce((e) => {
            usersState.q = e.target.value.trim();
            usersState.page = 1;
            loadUsers();
        }, 350));

        document.getElementById('newUserBtn')?.addEventListener('click', () => openUserModal(null));

        document.getElementById('usersBody')?.addEventListener('click', (event) => {
            const editBtn = event.target.closest('[data-edit-user]');
            if (editBtn) {
                openUserModal(JSON.parse(editBtn.dataset.editUser.replace(/&apos;/g, "'")));
                return;
            }
            const delBtn = event.target.closest('[data-delete-user]');
            if (delBtn) {
                if (!confirm('Excluir este usuário permanentemente?')) return;
                callApi('users_delete', { id: delBtn.dataset.deleteUser }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) loadUsers();
                });
            }
        });

        document.getElementById('userForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const id = document.getElementById('userId').value;
            const payload = {
                name: document.getElementById('userName').value,
                email: document.getElementById('userEmail').value,
                password: document.getElementById('userPassword').value,
                role: document.getElementById('userRole').value,
            };
            const resp = id
                ? await callApi('users_update', { id, ...payload })
                : await callApi('users_create', payload);

            showToast(resp.message, !resp.success);
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('userModal'))?.hide();
                loadUsers();
            }
        });
    }

    // ── LOJAS VTEX ───────────────────────────────────────────────────────────
    async function loadVtexStores() {
        const body = document.getElementById('vtexBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="4" class="text-muted">Carregando...</td></tr>';
        const resp = await callApi('vtex_stores_list');
        if (!resp.success) { body.innerHTML = '<tr><td colspan="4" class="text-danger">Erro ao carregar.</td></tr>'; return; }
        renderVtexStores(resp.data);
    }

    function renderVtexStores(lojas) {
        const body = document.getElementById('vtexBody');
        if (!body) return;
        body.innerHTML = lojas.length ? lojas.map((l) => `
            <tr>
                <td><strong>${escapeHtml(l.nome)}</strong></td>
                <td class="text-muted">${escapeHtml(l.dominio)}</td>
                <td><span class="status-badge status-${l.ativo ? 'success' : 'neutral'}"><i></i>${l.ativo ? 'Ativa' : 'Pausada'}</span></td>
                <td class="table-actions">
                    <button class="btn btn-sm btn-soft" data-toggle-vtex="${l.id}">${l.ativo ? 'Pausar' : 'Ativar'}</button>
                    <button class="btn btn-sm btn-soft-danger" data-remove-vtex="${l.id}"><i class="bi bi-trash"></i> Remover</button>
                </td>
            </tr>`).join('') : '<tr><td colspan="4" class="text-muted">Nenhuma loja VTEX cadastrada ainda.</td></tr>';
    }

    function initVtexStores() {
        document.getElementById('vtexAddForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const nome = document.getElementById('vtexNome');
            const dominio = document.getElementById('vtexDominio');
            const resp = await callApi('vtex_store_add', { nome: nome.value, dominio: dominio.value });
            showToast(resp.message, !resp.success);
            if (resp.success) {
                nome.value = '';
                dominio.value = '';
                renderVtexStores(resp.data);
            }
        });

        document.getElementById('vtexBody')?.addEventListener('click', (event) => {
            const toggleBtn = event.target.closest('[data-toggle-vtex]');
            if (toggleBtn) {
                callApi('vtex_store_toggle', { id: toggleBtn.dataset.toggleVtex }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) renderVtexStores(resp.data);
                });
                return;
            }
            const removeBtn = event.target.closest('[data-remove-vtex]');
            if (removeBtn) {
                if (!confirm('Remover esta loja VTEX da busca?')) return;
                callApi('vtex_store_remove', { id: removeBtn.dataset.removeVtex }).then((resp) => {
                    showToast(resp.message, !resp.success);
                    if (resp.success) renderVtexStores(resp.data);
                });
            }
        });
    }

    // ── CONFIGURAÇÕES ────────────────────────────────────────────────────────
    async function loadSettings() {
        const resp = await callApi('settings_get');
        if (resp.success) {
            document.getElementById('nomeSistemaInput').value = resp.data.nome_sistema;
            document.getElementById('logoSistemaInput').value = resp.data.logo_sistema || '';
            document.getElementById('maxResultadosInput').value = resp.data.max_resultados;
            document.getElementById('statusSistemaInput').value = resp.data.status_sistema;

            document.getElementById('cacheTtlInput').value = resp.data.cache_ttl_minutes;

            document.getElementById('meliClientId').value = resp.data.meli_client_id || '';
            document.getElementById('meliClientSecret').value = '';
            document.getElementById('meliClientSecret').placeholder = resp.data.meli_client_secret
                ? 'Já configurado — deixe em branco para manter'
                : 'Deixe em branco para manter o atual';
        }
        loadVtexStores();
    }

    function initSettings() {
        document.getElementById('sistemaForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const resp = await callApi('settings_update', {
                nome_sistema: document.getElementById('nomeSistemaInput').value,
                logo_sistema: document.getElementById('logoSistemaInput').value,
                max_resultados: document.getElementById('maxResultadosInput').value,
                status_sistema: document.getElementById('statusSistemaInput').value,
            });
            showToast(resp.message, !resp.success);
        });

        document.getElementById('settingsForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const resp = await callApi('settings_update', {
                cache_ttl_minutes: document.getElementById('cacheTtlInput').value,
            });
            showToast(resp.message, !resp.success);
        });

        document.getElementById('meliForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const secretInput = document.getElementById('meliClientSecret');
            const resp = await callApi('settings_update', {
                meli_client_id: document.getElementById('meliClientId').value,
                meli_client_secret: secretInput.value,
            });
            showToast(resp.message, !resp.success);
            if (resp.success) {
                secretInput.value = '';
                secretInput.placeholder = resp.data.meli_client_secret ? 'Já configurado — deixe em branco para manter' : 'Deixe em branco para manter o atual';
            }
        });

        document.getElementById('profileForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const resp = await callApi('profile_update', {
                name: document.getElementById('profileName').value,
                email: document.getElementById('profileEmail').value,
                password: document.getElementById('profilePassword').value,
            });
            showToast(resp.message, !resp.success);
            if (resp.success) {
                document.getElementById('profilePassword').value = '';
                document.querySelector('.profile-copy strong').textContent = document.getElementById('profileName').value;
                document.querySelector('.profile-copy small').textContent = document.getElementById('profileEmail').value;
            }
        });
    }

    // ── BOOT ─────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        initTheme();
        initSidebar();
        initGlobalSearch();
        initHistorico();
        initCategorias();
        initLojas();
        initCatalogo();
        initProdutos();
        initLogs();
        initUsers();
        initSettings();
        initVtexStores();

        sectionLoaders.dashboard = loadDashboard;
        sectionLoaders.historico = loadHistorico;
        sectionLoaders.produtos = loadCatalogo;
        sectionLoaders.categorias = loadCategorias;
        sectionLoaders.lojas = loadLojas;
        sectionLoaders.cacheProdutos = loadProdutos;
        sectionLoaders.scrapers = loadScrapers;
        sectionLoaders.logs = loadLogs;
        sectionLoaders.usuarios = loadUsers;
        sectionLoaders.configuracoes = loadSettings;

        document.getElementById('refreshDashboard')?.addEventListener('click', () => loadDashboard());

        initNavigation();
    });
})();
