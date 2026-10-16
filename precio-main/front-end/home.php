<?php
require_once __DIR__ . '/../back-end/includes/auth.php';
requireLogin();
$user    = currentUser();
$initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Precio — Pesquisar produto</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── Results Section ──────────────────────────────────────────────── */
        #results-section {
            display: none;
            padding: 0 48px 80px;
            max-width: 1280px;
            margin: 0 auto;
            width: 100%;
        }

        .results-meta {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            font-size: .9rem;
            color: var(--muted);
            padding: 18px 20px;
            border: 1px solid var(--stone);
            border-radius: var(--radius-md);
            background: color-mix(in srgb, var(--white) 78%, transparent);
            box-shadow: var(--shadow-sm);
            backdrop-filter: blur(12px);
        }

        .results-meta strong { color: var(--text); }

        .results-meta__title {
            display: block;
            color: var(--text);
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .results-timing {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: .82rem;
            color: var(--muted);
        }

        .results-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .summary-tile {
            background: var(--white);
            border: 1px solid var(--stone);
            border-radius: var(--radius-sm);
            padding: 12px;
            box-shadow: var(--shadow-sm);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
        }

        .summary-tile:hover {
            transform: translateY(-2px);
            border-color: var(--sky-pale);
            box-shadow: var(--shadow-md);
        }

        .summary-tile__label {
            display: block;
            color: var(--muted);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 3px;
        }

        .summary-tile__value {
            color: var(--text);
            font-size: .98rem;
            font-weight: 700;
        }

        .store-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 18px;
        }

        .store-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            background: var(--white);
            border: 1px solid var(--stone);
            color: var(--text-soft);
            font-size: .8rem;
            font-weight: 700;
            box-shadow: var(--shadow-sm);
            transition: transform .2s ease, border-color .2s ease;
        }

        .store-chip:hover {
            transform: translateY(-1px);
            border-color: var(--sky);
        }

        .store-chip__count {
            color: var(--muted);
            font-weight: 600;
        }

        .results-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Product card */
        .product-card {
            background: var(--white);
            border: 1.5px solid var(--stone);
            border-radius: var(--radius-md);
            overflow: hidden;
            display: flex;
            align-items: stretch;
            transition: transform .22s ease, border-color .22s ease, box-shadow .22s ease;
            text-decoration: none;
            color: inherit;
            min-height: 136px;
            width: 100%;
            opacity: 0;
            animation: productIn .34s ease forwards;
            position: relative;
            isolation: isolate;
        }

        .product-card::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(90deg, rgba(123,167,216,.10), transparent 38%);
            opacity: 0;
            transition: opacity .22s ease;
        }

        .product-card:hover {
            transform: translateY(-3px);
            border-color: var(--sky);
            box-shadow: var(--shadow-md);
        }

        .product-card:hover::after { opacity: 1; }

        .product-card__img {
            background: var(--off-white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: .75rem;
            width: 150px;
            min-width: 150px;
            font-size: 2.5rem;
        }

        .product-card__img img {
            max-height: 112px;
            max-width: 100%;
            object-fit: contain;
            transition: transform .24s ease;
        }

        .product-card:hover .product-card__img img { transform: scale(1.045); }

        .product-card__body {
            padding: 1rem;
            flex: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 16px;
        }

        .product-card__name {
            font-size: .94rem;
            color: var(--text-soft);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-card__store {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            margin-bottom: 6px;
            padding: 3px 7px;
            border-radius: 4px;
            background: var(--off-white);
            color: var(--muted);
            border: 1px solid var(--stone);
            font-size: .72rem;
            font-weight: 600;
        }

        .product-card__best {
            display: inline-flex;
            align-items: center;
            margin-left: 6px;
            padding: 3px 7px;
            border-radius: 4px;
            background: #EEF7F3;
            color: var(--success);
            border: 1px solid #B3DCC8;
            font-size: .72rem;
            font-weight: 700;
        }

        .product-card__pricing {
            min-width: 180px;
            text-align: right;
        }

        .product-card__orig {
            font-size: .78rem;
            color: var(--muted);
            text-decoration: line-through;
            margin-bottom: 2px;
        }

        .product-card__price {
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--indigo);
            letter-spacing: 0;
        }

        .product-card__badge {
            display: inline-block;
            background: #EEF7F3;
            color: var(--success);
            border: 1px solid #B3DCC8;
            border-radius: 4px;
            font-size: .7rem;
            font-weight: 600;
            padding: 1px 6px;
            margin-left: 6px;
            vertical-align: middle;
        }

        .product-card__out {
            display: inline-block;
            background: #FEF0F3;
            color: var(--danger);
            border: 1px solid #F2C0C9;
            border-radius: 4px;
            font-size: .7rem;
            padding: 1px 6px;
            margin-top: 4px;
        }

        .product-card__cta {
            display: block;
            margin-top: 10px;
            padding: 8px;
            /* Antes era rgba(61,74,107,.05) fixo (tom do indigo claro), então no
               dark mode — onde --indigo vira uma cor clara (#D7E3F4) — o botão
               ficava com um fundo quase invisível. color-mix com --indigo
               acompanha o tema atual nos dois modos. */
            background: color-mix(in srgb, var(--indigo) 6%, transparent);
            border: 1px solid color-mix(in srgb, var(--indigo) 12%, transparent);
            color: var(--indigo);
            border-radius: var(--radius-sm);
            text-align: center;
            font-size: .82rem;
            font-weight: 500;
            transition: background .18s, border-color .18s, color .18s, transform .18s;
        }

        /* Hover do card inteiro (efeito mais sutil, "aquece" o botão mesmo
           quando o cursor está em outra parte do card). */
        .product-card:hover .product-card__cta {
            background: var(--sky-pale);
            border-color: var(--sky);
        }

        /* BUG FIX: o botão "Ver na loja →" não tinha estado :hover próprio —
           dependia só do hover do card pai, então passar o mouse diretamente
           sobre o botão não dava nenhum feedback além do que já acontecia ao
           passar em qualquer outro ponto do card. Agora o hover direto no
           botão tem um destaque mais forte e visível (preenchimento sólido
           em --indigo), deixando claro que é um elemento clicável distinto. */
        .product-card__cta:hover {
            background: var(--indigo);
            border-color: var(--indigo);
            color: var(--white);
            transform: translateY(-1px);
        }

        @keyframes productIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .search-bar {
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
        }

        .search-bar:focus-within {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .search-bar__btn {
            position: relative;
            overflow: hidden;
        }

        .search-bar__btn::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,.28), transparent);
            transform: translateX(-120%);
            transition: transform .45s ease;
        }

        .search-bar__btn:hover::after { transform: translateX(120%); }

        /* Loading state */
        .results-loading {
            padding: 12px 0 20px;
        }

        .loading-panel {
            background: var(--white);
            border: 1px solid var(--stone);
            border-radius: var(--radius-md);
            padding: 18px;
            margin-bottom: 14px;
            box-shadow: var(--shadow-sm);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .loading-panel strong {
            display: block;
            color: var(--text);
            margin-bottom: 3px;
        }

        .skeleton-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .skeleton-card {
            height: 136px;
            border-radius: var(--radius-md);
            border: 1px solid var(--stone);
            /* Era hardcoded para tons claros (#F0EFEC/#FFFFFF), por isso ficava
               um bloco branco "queimado" sobre o fundo escuro no dark mode.
               Agora usa --stone/--off-white/--white do tema atual, então a base
               e o brilho do shimmer se adaptam automaticamente. */
            background:
                linear-gradient(90deg, color-mix(in srgb, var(--white) 0%, transparent), color-mix(in srgb, var(--white) 82%, transparent), color-mix(in srgb, var(--white) 0%, transparent)),
                linear-gradient(90deg, var(--stone) 0 150px, var(--off-white) 150px 100%);
            background-size: 220px 100%, 100% 100%;
            background-position: -220px 0, 0 0;
            animation: shimmer 1.05s infinite linear;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--stone);
            border-top-color: var(--indigo);
            border-radius: 50%;
            animation: spin .75s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes shimmer { to { background-position: calc(100% + 220px) 0, 0 0; } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* Error state */
        .results-error {
            background: #FEF0F3;
            border: 1px solid #F2C0C9;
            color: var(--danger);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            font-size: .9rem;
        }

        .results-notice {
            background: #F4F7FB;
            border: 1px solid #DDE6F3;
            color: var(--muted);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: .9rem;
        }

        /* Empty state */
        .results-empty {
            text-align: center;
            padding: 60px 0;
            color: var(--muted);
        }

        .results-empty .results-empty__icon {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 12px;
        }

        .results-empty p { font-size: .95rem; }
        .results-empty strong { color: var(--text-soft); }

        /* Button loading */
        .search-bar__btn[disabled] {
            opacity: .7;
            pointer-events: none;
        }

        @media (max-width: 860px) {
            #results-section { padding: 0 20px 60px; }
            .results-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 640px) {
            .results-meta { grid-template-columns: 1fr; }
            .results-summary { grid-template-columns: 1fr; }

            .product-card__img {
                width: 104px;
                min-width: 104px;
            }

            .product-card__body {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .product-card__pricing {
                min-width: 0;
                text-align: left;
            }
        }
    </style>
</head>
<body>

<!-- Background decorativo -->
<div class="bg-canvas" aria-hidden="true">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient id="g1" cx="15%" cy="30%" r="60%">
                <stop offset="0%" stop-color="#C8DDEF" stop-opacity=".35"/>
                <stop offset="100%" stop-color="#F7F6F4" stop-opacity="0"/>
            </radialGradient>
            <radialGradient id="g2" cx="85%" cy="70%" r="50%">
                <stop offset="0%" stop-color="#E3EBF6" stop-opacity=".4"/>
                <stop offset="100%" stop-color="#F7F6F4" stop-opacity="0"/>
            </radialGradient>
            <radialGradient id="g3" cx="50%" cy="100%" r="45%">
                <stop offset="0%" stop-color="#EDEBE7" stop-opacity=".7"/>
                <stop offset="100%" stop-color="#F7F6F4" stop-opacity="0"/>
            </radialGradient>
        </defs>
        <rect width="1440" height="900" fill="#F7F6F4"/>
        <rect width="1440" height="900" fill="url(#g1)"/>
        <rect width="1440" height="900" fill="url(#g2)"/>
        <rect width="1440" height="900" fill="url(#g3)"/>
        <circle cx="120"  cy="200" r="260" fill="none" stroke="#DDEAF5" stroke-width="1"/>
        <circle cx="120"  cy="200" r="190" fill="none" stroke="#DDEAF5" stroke-width="1" opacity=".6"/>
        <circle cx="1320" cy="700" r="220" fill="none" stroke="#DDEAF5" stroke-width="1"/>
        <circle cx="1320" cy="700" r="150" fill="none" stroke="#DDEAF5" stroke-width="1" opacity=".6"/>
        <circle cx="80"   cy="820" r="120" fill="#EDEBE7" opacity=".55"/>
        <circle cx="1380" cy="120" r="100" fill="#EDEBE7" opacity=".45"/>
        <circle cx="1360" cy="80"  r="45"  fill="#C8DDEF" opacity=".3"/>
        <circle cx="60"   cy="850" r="40"  fill="#C8DDEF" opacity=".25"/>
        <circle cx="300"  cy="100" r="3" fill="#7BA7D8" opacity=".12"/>
        <circle cx="360"  cy="140" r="2" fill="#7BA7D8" opacity=".10"/>
        <circle cx="1100" cy="780" r="3" fill="#7BA7D8" opacity=".12"/>
        <circle cx="1160" cy="820" r="2" fill="#7BA7D8" opacity=".10"/>
        <line x1="700" y1="800" x2="740" y2="800" stroke="#C8DDEF" stroke-width="1" opacity=".6"/>
        <line x1="720" y1="780" x2="720" y2="820" stroke="#C8DDEF" stroke-width="1" opacity=".6"/>
        <line x1="200" y1="400" x2="240" y2="400" stroke="#C8DDEF" stroke-width="1" opacity=".4"/>
        <line x1="220" y1="380" x2="220" y2="420" stroke="#C8DDEF" stroke-width="1" opacity=".4"/>
    </svg>
</div>

<div class="page">

    <!-- ── Navbar ────────────────────────────────────────────────────────────── -->
    <nav class="navbar">
        <a href="home.php" class="navbar__logo">
            <svg width="26" height="26" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="16" cy="16" r="15" stroke="#C8DDEF" stroke-width="1.5"/>
                <path d="M9 16 C9 12.13 12.13 9 16 9 C19.87 9 23 12.13 23 16" stroke="#7BA7D8" stroke-width="2" stroke-linecap="round"/>
                <path d="M16 9 L16 23" stroke="#5B6A8E" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="16" cy="16" r="3" fill="#3D4A6B"/>
            </svg>
            Precio
        </a>

        <div class="navbar__right">
            <div class="navbar__user">
                <div class="navbar__avatar"><?= htmlspecialchars($initial) ?></div>
                <span><?= htmlspecialchars($user['name']) ?></span>
            </div>
            <button class="cart-trigger" id="cart-trigger" aria-label="Abrir carrinho">
                <span class="cart-trigger__glow" aria-hidden="true"></span>
                <canvas class="cart3d-canvas" id="cart3d-canvas" aria-hidden="true"></canvas>
                <svg class="cart-fallback-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <span class="cart-trigger__badge" id="cart-badge">0</span>
            </button>
            <button class="theme-toggle theme-toggle--inline" id="theme-toggle-home" aria-label="Alternar modo escuro">
                <span class="theme-toggle__icon" id="theme-icon-home">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </span>
            </button>
            <button class="btn-logout" id="btn-logout">Sair</button>
        </div>
    </nav>

    <!-- ── Hero ──────────────────────────────────────────────────────────────── -->
    <main class="hero">
        <h1 class="hero__title">
            O que você quer<br>
            <em>comprar hoje?</em>
        </h1>
        <p class="hero__sub">
            Digite o nome do produto e o Precio busca os melhores preços em tempo real.
        </p>

        <!-- Search bar -->
        <div class="search-wrap">
            <div class="search-bar">
                <!-- Ícone lupa -->
                <span class="search-bar__icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>

                <input
                    class="search-bar__input"
                    id="search-input"
                    type="text"
                    placeholder="Ex: RTX 4060, Tênis, Mesa…"
                    autocomplete="off"
                    maxlength="120"
                >

                <button class="search-bar__btn" id="search-btn">Buscar</button>
            </div>

            <div class="search-hint">
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    Preços em tempo real
                </span>
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Lojas Parceiras
                </span>
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Resultado em segundos
                </span>
            </div>
        </div>

        <!-- Feature pills -->
        <div class="feature-pills">
            <div class="feature-pill">
                <span class="feature-pill__icon">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#3D4A6B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
                Dezenas de produtos
            </div>
            <div class="feature-pill">
                <span class="feature-pill__icon">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#3D4A6B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
                Preços em tempo real
            </div>
            <div class="feature-pill">
                <span class="feature-pill__icon">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#3D4A6B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
                Resultado em segundos
            </div>
            <div class="feature-pill">
                <span class="feature-pill__icon">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#3D4A6B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </span>
                100% gratuito
            </div>
        </div>

    </main>

    <!-- ── Results ────────────────────────────────────────────────────────────── -->
    <section id="results-section" aria-live="polite" aria-label="Resultados da busca"></section>

</div><!-- /.page -->

<!-- ── Carrinho ───────────────────────────────────────────────────────────────── -->
<div class="cart-overlay" id="cart-overlay"></div>

<aside class="cart-panel" id="cart-panel" aria-label="Carrinho de produtos">
    <div class="cart-panel__header">
        <div class="cart-panel__heading">
            <span class="cart-panel__title">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                Carrinho
                <span class="cart-panel__title-count" id="cart-panel-count">0</span>
            </span>
            <span class="cart-panel__subtitle" id="cart-panel-subtitle">Compare antes de comprar</span>
        </div>
        <button class="cart-panel__close" id="cart-close" aria-label="Fechar carrinho">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <div class="cart-panel__body" id="cart-body">
        <!-- Preenchido via JS -->
    </div>

    <div class="cart-panel__footer" id="cart-footer" style="display:none">
        <div class="cart-insight" id="cart-insight">
            <!-- Preenchido via JS -->
        </div>
        <div class="cart-summary">
            <span class="cart-summary__label">Total estimado</span>
            <span class="cart-summary__value-wrap">
                <span class="cart-summary__currency">R$</span>
                <span class="cart-summary__value" id="cart-total">0,00</span>
            </span>
        </div>
        <span class="cart-summary__note">Soma dos preços encontrados na busca — confira o valor final em cada loja.</span>
        <button class="cart-btn-viewall" id="cart-viewall">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
            </svg>
            Ver todos produtos na loja
        </button>
        <button class="cart-btn-clear" id="cart-clear">Esvaziar carrinho</button>
    </div>
</aside>

<div class="cart-toast" id="cart-toast">
    <span class="cart-toast__icon">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
    </span>
    <span id="cart-toast-msg">Produto adicionado ao carrinho</span>
</div>

<!-- Three.js e GSAP via CDN — usados pelo carrinho 3D e pelas animações de
     "voar para o carrinho" em assets/js/cart3d.js. Carregados de forma
     defensiva: se falharem (rede bloqueada, ad-blocker), o site cai de
     volta para o ícone plano original sem quebrar nada. -->
<script src="https://unpkg.com/three@0.128.0/build/three.min.js"></script>
<script src="https://unpkg.com/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="assets/js/cart3d.js"></script>

<script>
(function() {
    var root = document.documentElement;
    var btn  = document.getElementById('theme-toggle-home');
    var icon = document.getElementById('theme-icon-home');
    var KEY  = 'precio-theme';

    var ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    var ICON_SUN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';

    function applyTheme(theme) {
        root.dataset.theme = theme;
        if (icon) icon.innerHTML = theme === 'dark' ? ICON_SUN : ICON_MOON;
        if (btn)  btn.setAttribute('aria-label', theme === 'dark' ? 'Mudar para modo claro' : 'Mudar para modo escuro');
    }
    var stored    = localStorage.getItem(KEY);
    var preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    applyTheme(stored || preferred);
    if (btn) {
        btn.addEventListener('click', function() {
            var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            localStorage.setItem(KEY, next);
        });
    }
})();

// ── Logout ────────────────────────────────────────────────────────────────────
document.getElementById('btn-logout').addEventListener('click', async () => {
    const btn = document.getElementById('btn-logout');
    btn.textContent = 'Saindo…';
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'logout');
        const res  = await fetch('../back-end/api.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) window.location.href = json.redirect;
    } catch {
        window.location.href = 'index.php';
    }
});

// ── Carrinho ──────────────────────────────────────────────────────────────────
const Cart = (() => {
    // Chave por usuário: cada conta tem seu próprio carrinho persistido no navegador.
    const STORAGE_KEY = 'precio-cart-<?= htmlspecialchars(json_encode($user['id']), ENT_QUOTES) ?>';

    function load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    }

    function save(items) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
        } catch {
            // localStorage indisponível (modo privado/quota) — carrinho funciona só na sessão atual
        }
    }

    let items = load();

    function getAll() {
        return items;
    }

    function has(id) {
        return items.some(item => item.id === id);
    }

    function add(produto) {
        if (!produto || !produto.id || has(produto.id)) return false;
        items.push(produto);
        save(items);
        return true;
    }

    function remove(id) {
        const before = items.length;
        items = items.filter(item => item.id !== id);
        save(items);
        return items.length !== before;
    }

    function clear() {
        items = [];
        save(items);
    }

    return { getAll, has, add, remove, clear };
})();

const cartTrigger   = document.getElementById('cart-trigger');
const cartOverlay   = document.getElementById('cart-overlay');
const cartPanel     = document.getElementById('cart-panel');
const cartClose     = document.getElementById('cart-close');
const cartBody      = document.getElementById('cart-body');
const cartFooter    = document.getElementById('cart-footer');
const cartBadge     = document.getElementById('cart-badge');
const cartPanelCount= document.getElementById('cart-panel-count');
const cartPanelSubtitle = document.getElementById('cart-panel-subtitle');
const cartInsight   = document.getElementById('cart-insight');
const cartTotal     = document.getElementById('cart-total');
const cartViewAllBtn= document.getElementById('cart-viewall');
const cartClearBtn  = document.getElementById('cart-clear');
const cartToast     = document.getElementById('cart-toast');
const cartToastMsg  = document.getElementById('cart-toast-msg');

function openCart() {
    renderCartPanel();
    cartOverlay.classList.add('open');
    cartPanel.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCart() {
    cartOverlay.classList.remove('open');
    cartPanel.classList.remove('open');
    document.body.style.overflow = '';
}

cartTrigger.addEventListener('click', openCart);
cartClose.addEventListener('click', closeCart);
cartOverlay.addEventListener('click', closeCart);
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && cartPanel.classList.contains('open')) closeCart();
});

function showToast(msg) {
    cartToastMsg.textContent = msg;
    cartToast.classList.add('show');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => cartToast.classList.remove('show'), 2200);
}

// Formata só o número (sem "R$") para o total em destaque no footer do carrinho.
function formatBRLNumero(valor) {
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(valor));
}

function renderCartBadge() {
    const count = Cart.getAll().length;
    cartBadge.textContent = count > 99 ? '99+' : String(count);
    cartBadge.classList.toggle('show', count > 0);
    cartPanelCount.textContent = String(count);
}

function renderCartPanel() {
    const all = Cart.getAll();
    renderCartBadge();

    if (all.length === 0) {
        cartPanelSubtitle.textContent = 'Compare antes de comprar';
        cartBody.innerHTML = `
            <div class="cart-empty">
                <svg class="cart-empty__illo" width="96" height="96" viewBox="0 0 96 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="48" cy="48" r="46" stroke="currentColor" stroke-opacity=".14" stroke-width="2" stroke-dasharray="4 6"/>
                    <path d="M28 34h6l4.2 28.6a4 4 0 0 0 4 3.4h17.4a4 4 0 0 0 3.95-3.34L67 40H38" stroke="currentColor" stroke-opacity=".55" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="42" cy="74" r="2.6" fill="currentColor" fill-opacity=".55"/>
                    <circle cx="60" cy="74" r="2.6" fill="currentColor" fill-opacity=".55"/>
                    <path d="M43 47l3.4 3.6L53 44" stroke="var(--sky)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <strong>Seu carrinho está vazio</strong>
                <p>Pesquise um produto e clique em "Adicionar" para guardá-lo aqui e comparar preços entre lojas.</p>
            </div>
        `;
        cartFooter.style.display = 'none';
        return;
    }

    cartFooter.style.display = 'flex';
    cartPanelSubtitle.textContent = all.length === 1 ? '1 produto para comparar' : `${all.length} produtos para comparar`;

    let total = 0;
    let temPrecoDesconhecido = false;

    // Agrupa por loja — é a vantagem real de um carrinho de comparação: ver de
    // onde vêm os itens e o subtotal de cada loja, não só a soma final.
    const porLoja = new Map();
    for (const item of all) {
        const loja = item.loja || 'Loja não identificada';
        if (!porLoja.has(loja)) porLoja.set(loja, []);
        porLoja.get(loja).push(item);

        if (item.preco !== null && item.preco !== undefined) {
            total += Number(item.preco);
        } else {
            temPrecoDesconhecido = true;
        }
    }

    const gruposHtml = [...porLoja.entries()].map(([loja, itens]) => {
        let subtotal = 0;
        let subtotalIncompleto = false;
        for (const item of itens) {
            if (item.preco !== null && item.preco !== undefined) subtotal += Number(item.preco);
            else subtotalIncompleto = true;
        }

        const itemsHtml = itens.map(item => {
            const imgHtml = item.imagem
                ? `<img src="${escHtml(item.imagem)}" alt="${escHtml(item.nome)}" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                   <span class="cart-item__img-fallback" style="display:none">📦</span>`
                : `<span class="cart-item__img-fallback">📦</span>`;
            const precoTxt = item.preco !== null && item.preco !== undefined ? formatBRL(item.preco) : 'Ver preço no site';

            return `
                <div class="cart-item" data-id="${escHtml(item.id)}">
                    <div class="cart-item__img">${imgHtml}</div>
                    <div class="cart-item__body">
                        <span class="cart-item__name">${escHtml(item.nome)}</span>
                        <span class="cart-item__price">${precoTxt}</span>
                    </div>
                    <div class="cart-item__actions">
                        <a class="cart-item__link" href="${escHtml(item.url)}" target="_blank" rel="noopener noreferrer" title="Ver na loja" aria-label="Ver na loja">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                        <button class="cart-item__remove" data-remove-id="${escHtml(item.id)}" title="Remover" aria-label="Remover do carrinho">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="cart-group">
                <div class="cart-group__header">
                    <span class="cart-group__store">
                        <span class="cart-group__dot"></span>
                        ${escHtml(loja)}
                    </span>
                    <span class="cart-group__subtotal">${formatBRL(subtotal)}${subtotalIncompleto ? '+' : ''}</span>
                </div>
                <div class="cart-items">${itemsHtml}</div>
            </div>
        `;
    }).join('');

    cartBody.innerHTML = gruposHtml;
    cartTotal.textContent = formatBRLNumero(total) + (temPrecoDesconhecido ? '+' : '');

    // Insight: qual loja concentra mais itens do carrinho — útil para decidir
    // onde finalizar a compra e evitar frete duplicado.
    if (porLoja.size > 1) {
        const [lojaTop, itensTop] = [...porLoja.entries()].sort((a, b) => b[1].length - a[1].length)[0];
        cartInsight.style.display = 'flex';
        cartInsight.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20M2 12h20"/><circle cx="12" cy="12" r="9"/>
            </svg>
            <span><strong>${escHtml(lojaTop)}</strong> concentra ${itensTop.length} de ${all.length} itens — finalizar por lá pode economizar no frete.</span>
        `;
    } else {
        cartInsight.style.display = 'none';
        cartInsight.innerHTML = '';
    }

    // Remoção individual
    cartBody.querySelectorAll('[data-remove-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.removeId;
            // BUG FIX: o id é a própria URL do produto, que pode conter
            // caracteres (?, &, :, /) inválidos em seletores CSS. Em vez de
            // montar um seletor com a URL (via CSS.escape), sobe ao
            // ancestral .cart-item diretamente.
            const el = btn.closest('.cart-item');
            Cart.remove(id);
            syncAddCartButtons();
            if (el) {
                el.classList.add('removing');
                el.addEventListener('animationend', () => renderCartPanel(), { once: true });
            } else {
                renderCartPanel();
            }
        });
    });
}

// Sincroniza o estado visual (texto/cor) dos botões "Adicionar" nos cards de
// resultado com o que já está no carrinho — útil após remover algo no painel.
function syncAddCartButtons() {
    document.querySelectorAll('.product-card__add-cart').forEach(btn => {
        try {
            const produto = JSON.parse(decodeURIComponent(atob(btn.dataset.produto)));
            const inCart  = Cart.has(produto.id);
            btn.classList.toggle('added', inCart);
            btn.querySelector('.add-cart-label').textContent = inCart ? 'No carrinho' : 'Adicionar';
        } catch {
            /* ignora cards malformados */
        }
    });
}

function ligarBotoesCarrinho(scope) {
    scope.querySelectorAll('.product-card__add-cart').forEach(btn => {
        let produto;
        try {
            produto = JSON.parse(decodeURIComponent(atob(btn.dataset.produto)));
        } catch {
            return;
        }

        if (Cart.has(produto.id)) {
            btn.classList.add('added');
            btn.querySelector('.add-cart-label').textContent = 'No carrinho';
        }

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (Cart.has(produto.id)) {
                showToast('Esse produto já está no carrinho');
                return;
            }
            const added = Cart.add(produto);
            if (added) {
                btn.classList.add('added');
                btn.classList.add('pop-anim');
                setTimeout(() => btn.classList.remove('pop-anim'), 400);
                btn.querySelector('.add-cart-label').textContent = 'No carrinho';
                renderCartBadge();
                showToast('Produto adicionado ao carrinho');
                if (window.Cart3D) {
                    window.Cart3D.flyToCart(btn, produto.imagem || btn.dataset.img);
                }
            }
        });
    });
}

cartViewAllBtn.addEventListener('click', () => {
    const all = Cart.getAll();
    if (all.length === 0) return;

    if (all.length > 10) {
        const confirmar = window.confirm(
            `Há mais de 10 itens no carrinho (${all.length}), tem certeza que quer abrir essas páginas?`
        );
        if (!confirmar) return;
    }

    // Pop-up blockers costumam bloquear múltiplas chamadas de window.open em sequência.
    // Abrimos o primeiro link diretamente (gesto do usuário) e avisamos sobre o restante.
    let bloqueadas = 0;
    all.forEach((item) => {
        const win = window.open(item.url, '_blank', 'noopener,noreferrer');
        if (!win) bloqueadas++;
    });
    if (bloqueadas > 0) {
        showToast(`Seu navegador bloqueou ${bloqueadas} aba${bloqueadas > 1 ? 's' : ''}. Permita pop-ups para abrir todos os produtos.`);
    }
});

cartClearBtn.addEventListener('click', () => {
    if (Cart.getAll().length === 0) return;
    Cart.clear();
    renderCartPanel();
    syncAddCartButtons();
    showToast('Carrinho esvaziado');
});

renderCartBadge();

// ── Search ────────────────────────────────────────────────────────────────────
const searchInput    = document.getElementById('search-input');
const searchBtn      = document.getElementById('search-btn');
const resultsSection = document.getElementById('results-section');

// Formata valor em BRL: 1234.5 → "R$ 1.234,50"
function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(Number(valor));
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Gera um ID estável para o produto a partir da URL (identificador mais
// confiável que temos vindo do scraping — nome pode repetir, URL não).
function productId(p) {
    return String(p.url || p.nome || '').trim();
}

function mostrarLoading() {
    resultsSection.style.display = 'block';
    resultsSection.innerHTML = `
        <div class="results-loading">
            <div class="loading-panel">
                <div>
                    <strong>Comparando lojas em paralelo</strong>
                    <span> estão sendo consultadas agora.</span>
                </div>
                <div class="spinner"></div>
            </div>
            <div class="skeleton-list">
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
            </div>
        </div>
    `;
    resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function mostrarErro(msg) {
    resultsSection.style.display = 'block';
    resultsSection.innerHTML = `<div class="results-error">⚠️ ${escHtml(msg)}</div>`;
    resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function mostrarResultados(data, tempo, doCache) {
    const produtos = data.produtos || [];
    const total    = data.total    || 0;

    if (produtos.length === 0) {
        resultsSection.style.display = 'block';
        resultsSection.innerHTML = `
            <div class="results-empty">
                <span class="results-empty__icon">🔎</span>
                <p>Nenhum produto encontrado. Tente outro termo de busca.</p>
            </div>
        `;
        resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    const termoExibido = escHtml(searchInput.value.trim());
    const melhorPreco = produtos.reduce((menor, p) => {
        if (p.preco === null || p.preco === undefined) return menor;
        const preco = Number(p.preco);
        return menor === null || preco < menor ? preco : menor;
    }, null);
    const lojas = data.lojas || {};
    const lojasHtml = Object.entries(lojas)
        .sort((a, b) => b[1] - a[1])
        .map(([loja, qtd]) => `<span class="store-chip">${escHtml(loja)} <span class="store-chip__count">${qtd}</span></span>`)
        .join('');
    const menorPrecoHtml = melhorPreco !== null ? formatBRL(melhorPreco) : '-';
    const fonte = data.fonte ? escHtml(data.fonte) : 'lojas disponíveis';

    let html = `
        <div class="results-meta">
            <span>
                <span class="results-meta__title">${total} oferta${total !== 1 ? 's' : ''} para "${termoExibido}"</span>
                Ordenadas por menor preço em ${fonte}
            </span>
            <span class="results-timing">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
                ${tempo}s
            </span>
        </div>
        <div class="results-summary">
            <div class="summary-tile">
                <span class="summary-tile__label">Menor preço</span>
                <span class="summary-tile__value">${menorPrecoHtml}</span>
            </div>
            <div class="summary-tile">
                <span class="summary-tile__label">Ofertas</span>
                <span class="summary-tile__value">${total}</span>
            </div>
            <div class="summary-tile">
                <span class="summary-tile__label">Lojas</span>
                <span class="summary-tile__value">${Object.keys(lojas).length || '-'}</span>
            </div>
            <div class="summary-tile">
                <span class="summary-tile__label">Tempo</span>
                <span class="summary-tile__value">${tempo}s</span>
            </div>
        </div>
        ${lojasHtml ? `<div class="store-chips">${lojasHtml}</div>` : ''}
        ${data.aviso ? `<div class="results-notice">${escHtml(data.aviso)}</div>` : ''}
        <div class="results-grid">
    `;

    for (const [index, p] of produtos.entries()) {
        const preco     = p.preco !== null && p.preco !== undefined ? formatBRL(p.preco) : null;
        const precoOrig = p.preco_orig ? formatBRL(p.preco_orig) : null;
        const isBest    = melhorPreco !== null && Number(p.preco) === melhorPreco;
        const imgHtml   = p.imagem
            ? `<img src="${escHtml(p.imagem)}" alt="${escHtml(p.nome)}" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
               <span style="display:none;font-size:2.5rem">📦</span>`
            : `<span style="font-size:2.5rem">📦</span>`;

        // Produto serializado em base64 para o botão de carrinho (evita escapar JSON dentro de atributo HTML)
        const produtoData = btoa(encodeURIComponent(JSON.stringify({
            id:     productId(p),
            nome:   p.nome,
            preco:  p.preco ?? null,
            imagem: p.imagem || '',
            url:    p.url,
            loja:   p.loja || ''
        })));

        html += `
            <div class="product-card" style="animation-delay:${Math.min(index * 22, 420)}ms; cursor:default;">
                <a class="product-card__img" href="${escHtml(p.url)}" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">${imgHtml}</a>
                <div class="product-card__body">
                    <div>
                        ${p.loja ? `<span class="product-card__store">${escHtml(p.loja)}</span>` : ''}
                        ${isBest ? `<span class="product-card__best">Melhor preço</span>` : ''}
                        <a class="product-card__name" href="${escHtml(p.url)}" target="_blank" rel="noopener noreferrer" style="text-decoration:none;color:inherit;">${escHtml(p.nome)}</a>
                    </div>
                    <div class="product-card__pricing">
                        ${precoOrig ? `<div class="product-card__orig">${precoOrig}</div>` : ''}
                        <div>
                            <span class="product-card__price">${preco || 'Ver preço no site'}</span>
                            ${p.desconto ? `<span class="product-card__badge">-${p.desconto}%</span>` : ''}
                        </div>
                        ${!p.em_estoque ? `<span class="product-card__out">Sem estoque</span>` : ''}
                    </div>
                    <div class="product-card__actions">
                        <a class="product-card__cta" href="${escHtml(p.url)}" target="_blank" rel="noopener noreferrer">Ver na loja →</a>
                        <button class="product-card__add-cart" type="button" data-produto="${produtoData}" data-img="${escHtml(p.imagem || '')}">
                            <span class="flip-cart-icon">
                                <span class="flip-cart-icon__face flip-cart-icon__face--front">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                                    </svg>
                                </span>
                                <span class="flip-cart-icon__face flip-cart-icon__face--back">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </span>
                            </span>
                            <span class="add-cart-label">Adicionar</span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    html += '</div>';

    resultsSection.style.display = 'block';
    resultsSection.innerHTML = html;
    resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

    ligarBotoesCarrinho(resultsSection);
    if (window.Cart3D) window.Cart3D.refreshTilt(resultsSection);
}

async function realizarBusca() {
    const termo = searchInput.value.trim();

    if (termo.length < 2) {
        mostrarErro('Digite pelo menos 2 caracteres para pesquisar.');
        return;
    }

    searchBtn.disabled    = true;
    searchBtn.textContent = 'Buscando…';
    mostrarLoading();

    try {
        const fd = new FormData();
        fd.append('action', 'search');
        fd.append('busca', termo);

        const res  = await fetch('../back-end/api.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (json.success) {
            mostrarResultados(json.data, json.tempo, json.do_cache);
        } else {
            mostrarErro(json.message || 'Erro ao realizar a busca.');
        }
    } catch (err) {
        mostrarErro('Erro de comunicação. Verifique sua conexão e tente novamente.');
    } finally {
        searchBtn.disabled    = false;
        searchBtn.textContent = 'Buscar';
    }
}

searchBtn.addEventListener('click', realizarBusca);

searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') realizarBusca();
});
</script>
</body>
</html>