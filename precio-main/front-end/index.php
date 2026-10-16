<?php
require_once __DIR__ . '/../back-end/includes/auth.php';
if (isLoggedIn()) {
    header('Location: home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Precio — Compare preços em segundos</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- Background decorativo -->
<div class="bg-canvas" aria-hidden="true">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient id="grad1" cx="20%" cy="20%" r="55%">
                <stop offset="0%" stop-color="#C8DDEF" stop-opacity=".45"/>
                <stop offset="100%" stop-color="#F7F6F4" stop-opacity="0"/>
            </radialGradient>
            <radialGradient id="grad2" cx="80%" cy="80%" r="50%">
                <stop offset="0%" stop-color="#E3EBF6" stop-opacity=".5"/>
                <stop offset="100%" stop-color="#F7F6F4" stop-opacity="0"/>
            </radialGradient>
        </defs>
        <rect width="1440" height="900" fill="#F7F6F4"/>
        <rect width="1440" height="900" fill="url(#grad1)"/>
        <rect width="1440" height="900" fill="url(#grad2)"/>
        <!-- Círculos decorativos suaves -->
        <circle cx="1350" cy="80"  r="180" fill="#EDEBE7" opacity=".6"/>
        <circle cx="1300" cy="200" r="80"  fill="#C8DDEF" opacity=".3"/>
        <circle cx="100"  cy="820" r="220" fill="#EDEBE7" opacity=".5"/>
        <circle cx="180"  cy="760" r="70"  fill="#C8DDEF" opacity=".25"/>
        <circle cx="700"  cy="60"  r="40"  fill="#7BA7D8" opacity=".08"/>
        <circle cx="1100" cy="700" r="30"  fill="#7BA7D8" opacity=".08"/>
    </svg>
</div>

<div class="page auth-page">

    <!-- ── Painel esquerdo (branding) ───────────────────────────────────────── -->
    <div class="auth-brand">
        <!-- SVG decorativo interno -->
        <div class="auth-brand__deco" aria-hidden="true">
            <svg viewBox="0 0 600 800" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;position:absolute;top:0;left:0">
                <!-- Semicírculo superior direito -->
                <path d="M600 0 A240 240 0 0 0 360 0 L600 0 Z" fill="rgba(255,255,255,.04)"/>
                <!-- Círculo médio -->
                <circle cx="540" cy="400" r="200" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="1.5"/>
                <circle cx="540" cy="400" r="140" fill="none" stroke="rgba(255,255,255,.05)" stroke-width="1"/>
                <!-- Arco inferior esquerdo -->
                <path d="M0 800 A280 280 0 0 1 280 520" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="1.5"/>
                <!-- Pontos flutuantes -->
                <circle cx="80"  cy="100" r="4" fill="rgba(200,221,239,.5)"/>
                <circle cx="160" cy="180" r="3" fill="rgba(200,221,239,.4)"/>
                <circle cx="500" cy="700" r="5" fill="rgba(200,221,239,.35)"/>
                <circle cx="420" cy="760" r="3" fill="rgba(200,221,239,.3)"/>
                <circle cx="200" cy="600" r="4" fill="rgba(200,221,239,.25)"/>
                <!-- Linha diagonal sutil -->
                <line x1="0" y1="500" x2="200" y2="300" stroke="rgba(255,255,255,.04)" stroke-width="1"/>
            </svg>
        </div>

        <div class="auth-brand__logo">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="16" cy="16" r="15" stroke="rgba(255,255,255,.3)" stroke-width="1.5"/>
                <path d="M9 16 C9 12.13 12.13 9 16 9 C19.87 9 23 12.13 23 16" stroke="#7BA7D8" stroke-width="2" stroke-linecap="round"/>
                <path d="M16 9 L16 23" stroke="rgba(255,255,255,.5)" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="16" cy="16" r="3" fill="#7BA7D8"/>
            </svg>
            Precio
        </div>

        <h1 class="auth-brand__headline">
            Compare preços.<br>
            <em>Compre melhor.</em>
        </h1>
        <p class="auth-brand__sub">
            Digite o nome de qualquer produto e o Precio encontra as melhores ofertas em Pichau, KaBuM! e Amazon — em segundos.
        </p>

        <div class="auth-brand__tags">
            <span class="auth-brand__tag">🔍 Web scraping</span>
            <span class="auth-brand__tag">💸 Melhores preços</span>
            <span class="auth-brand__tag">⚡ Resultado rápido</span>
        </div>
    </div>

    <!-- ── Painel direito (formulário) ──────────────────────────────────────── -->
    <div class="auth-panel">
        <div class="auth-box">

            <!-- Tabs -->
            <div class="auth-tabs" role="tablist">
                <button class="auth-tab active" role="tab" aria-selected="true"  data-tab="login">    Entrar</button>
                <button class="auth-tab"        role="tab" aria-selected="false" data-tab="register"> Cadastrar</button>
            </div>

            <!-- ── Login form ─────────────────────────────────────────────── -->
            <div class="auth-form active" id="form-login">
                <h2 class="auth-form__title">Bem-vindo de volta</h2>
                <p class="auth-form__subtitle">Entre para continuar comparando preços.</p>

                <div class="alert" id="alert-login"></div>

                <div class="field">
                    <label for="login-email">E-mail</label>
                    <input type="email" id="login-email" placeholder="seu@email.com" autocomplete="email">
                </div>
                <div class="field">
                    <label for="login-password">Senha</label>
                    <div class="field__password">
                        <input type="password" id="login-password" placeholder="••••••••" autocomplete="current-password">
                        <button type="button" class="password-toggle" data-toggle-password="login-password" aria-label="Mostrar senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="btn-primary" id="btn-login">Entrar</button>
            </div>

            <!-- ── Register form ──────────────────────────────────────────── -->
            <div class="auth-form" id="form-register">
                <h2 class="auth-form__title">Criar conta</h2>
                <p class="auth-form__subtitle">Grátis, rápido e sem complicação.</p>

                <div class="alert" id="alert-register"></div>

                <div class="field">
                    <label for="reg-name">Nome</label>
                    <input type="text" id="reg-name" placeholder="Seu nome" autocomplete="name">
                </div>
                <div class="field">
                    <label for="reg-email">E-mail</label>
                    <input type="email" id="reg-email" placeholder="seu@email.com" autocomplete="email">
                </div>
                <div class="field">
                    <label for="reg-password">Senha</label>
                    <div class="field__password">
                        <input type="password" id="reg-password" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-toggle-password="reg-password" aria-label="Mostrar senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label for="reg-confirm">Confirmar senha</label>
                    <div class="field__password">
                        <input type="password" id="reg-confirm" placeholder="••••••••" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-toggle-password="reg-confirm" aria-label="Mostrar senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="btn-primary" id="btn-register">Criar conta</button>
            </div>

        </div>
    </div>

</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
document.querySelectorAll('.auth-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.auth-tab, .auth-form').forEach(el => el.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('form-' + tab.dataset.tab).classList.add('active');
    });
});

// ── Alert helper ─────────────────────────────────────────────────────────────
function showAlert(id, msg, type = 'error') {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.className = `alert ${type} show`;
}
function hideAlert(id) {
    const el = document.getElementById(id);
    el.className = 'alert';
}

// ── API request ───────────────────────────────────────────────────────────────
async function apiPost(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const res  = await fetch('../back-end/api.php', { method: 'POST', body: fd });
    return res.json();
}

// ── Login ─────────────────────────────────────────────────────────────────────
document.getElementById('btn-login').addEventListener('click', async () => {
    hideAlert('alert-login');
    const email    = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;
    const btn      = document.getElementById('btn-login');

    if (!email || !password) { showAlert('alert-login', 'Preencha todos os campos.'); return; }

    btn.disabled    = true;
    btn.textContent = 'Entrando…';

    try {
        const json = await apiPost({ action: 'login', email, password });
        if (json.success) {
            showAlert('alert-login', 'Sucesso! Redirecionando…', 'success');
            setTimeout(() => window.location.href = json.redirect, 700);
        } else {
            showAlert('alert-login', json.message);
        }
    } catch {
        showAlert('alert-login', 'Erro de comunicação. Tente novamente.');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Entrar';
    }
});

// ── Cadastro ──────────────────────────────────────────────────────────────────
document.getElementById('btn-register').addEventListener('click', async () => {
    hideAlert('alert-register');
    const name     = document.getElementById('reg-name').value.trim();
    const email    = document.getElementById('reg-email').value.trim();
    const password = document.getElementById('reg-password').value;
    const confirm  = document.getElementById('reg-confirm').value;
    const btn      = document.getElementById('btn-register');

    btn.disabled    = true;
    btn.textContent = 'Criando conta…';

    try {
        const json = await apiPost({ action: 'register', name, email, password, confirm });
        if (json.success) {
            showAlert('alert-register', 'Conta criada! Redirecionando…', 'success');
            setTimeout(() => window.location.href = json.redirect, 700);
        } else {
            showAlert('alert-register', json.message);
        }
    } catch {
        showAlert('alert-register', 'Erro de comunicação. Tente novamente.');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Criar conta';
    }
});

// Enter key support
document.getElementById('login-password').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('btn-login').click();
});
document.getElementById('reg-confirm').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('btn-register').click();
});

// ── Mostrar/ocultar senha ─────────────────────────────────────────────────────
(function() {
    var ICON_EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var ICON_EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.61 21.61 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-3.22 4.53M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(btn.dataset.togglePassword);
            if (!input) return;
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-pressed', String(!showing));
            btn.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
            btn.innerHTML = showing ? ICON_EYE : ICON_EYE_OFF;
        });
    });
})();

// Dark Mode sync
(function() {
    var theme = localStorage.getItem('precio-theme') ||
                (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
})();
</script>
</body>
</html>
