/**
 * cart3d.js — Precio
 * ─────────────────────────────────────────────────────────────────────────
 * Adiciona estruturas 3D reais (Three.js) e animações (GSAP) ao site:
 *
 *   1. Um carrinho 3D interativo na navbar (WebGL, low-poly, construído com
 *      geometria procedural — sem depender de arquivos .glb externos).
 *      Reage ao mouse (tilt), tem uma animação idle e "comemora" quando um
 *      produto é adicionado (pulso + giro de rodas + brilho).
 *   2. Uma peça decorativa 3D flutuante no hero (grade de caixas + anel),
 *      dando profundidade real à página.
 *   3. Uma animação "voar para o carrinho": quando o usuário clica em
 *      "Adicionar", uma miniatura do produto percorre um arco curvo (GSAP)
 *      até o ícone do carrinho, então o carrinho 3D reage.
 *   4. Tilt 3D real (perspective + rotateX/rotateY) nos cards de produto,
 *      seguindo o cursor.
 *
 * Tudo é escrito para degradar graciosamente: se Three.js ou GSAP não
 * carregarem (rede bloqueada, ad-blocker etc.), o site cai de volta para o
 * ícone plano original e as interações 2D já existentes continuam intactas.
 */
(function () {
    'use strict';

    var HAS_THREE = typeof window.THREE !== 'undefined';
    var HAS_GSAP  = typeof window.gsap  !== 'undefined';
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // Lê uma variável CSS (tema atual) e devolve um THREE.Color.
    function cssVarColor(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        try {
            return new THREE.Color(v || fallback);
        } catch (e) {
            return new THREE.Color(fallback);
        }
    }

    // ── 1. Carrinho 3D da navbar ───────────────────────────────────────────
    var CartIcon3D = (function () {
        var canvas, renderer, scene, camera, cartGroup, wheels = [], basketMat, ambient, key;
        var pointerX = 0, pointerY = 0, targetRotX = 0, targetRotY = 0;
        var raf = null, running = false;

        function buildCart() {
            var group = new THREE.Group();

            var indigo = cssVarColor('--indigo', '#3D4A6B');
            var sky    = cssVarColor('--sky', '#7BA7D8');

            basketMat = new THREE.MeshStandardMaterial({
                color: sky,
                metalness: 0.15,
                roughness: 0.45,
                transparent: true,
                opacity: 0.88,
                side: THREE.DoubleSide,
                emissive: new THREE.Color(0x000000),
                emissiveIntensity: 0
            });

            var edgeMat = new THREE.LineBasicMaterial({ color: indigo, linewidth: 1 });
            var frameMat = new THREE.MeshStandardMaterial({ color: indigo, metalness: 0.3, roughness: 0.4 });

            // Cesto (caixa aberta em cima e na frente) — 4 painéis finos
            var basket = new THREE.Group();
            var panelBack = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 0.85), basketMat);
            panelBack.position.set(0, 0, -0.55);
            basket.add(panelBack);

            var panelBottom = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 1.1), basketMat);
            panelBottom.rotation.x = -Math.PI / 2;
            panelBottom.position.set(0, -0.42, 0);
            basket.add(panelBottom);

            var panelLGeo = new THREE.PlaneGeometry(1.1, 0.85);
            var panelL = new THREE.Mesh(panelLGeo, basketMat);
            panelL.rotation.y = Math.PI / 2;
            panelL.position.set(-0.75, 0, 0);
            basket.add(panelL);

            var panelR = new THREE.Mesh(panelLGeo, basketMat);
            panelR.rotation.y = -Math.PI / 2;
            panelR.position.set(0.75, 0, 0);
            basket.add(panelR);

            // Contorno para leitura nítida em tamanho pequeno
            [panelBack, panelBottom, panelL, panelR].forEach(function (p) {
                var edges = new THREE.EdgesGeometry(p.geometry);
                var line = new THREE.LineSegments(edges, edgeMat);
                p.add(line);
            });

            basket.rotation.x = -0.06;
            group.add(basket);

            // Alça (arco parcial de torus)
            var handleGeo = new THREE.TorusGeometry(0.42, 0.045, 8, 24, Math.PI);
            var handle = new THREE.Mesh(handleGeo, frameMat);
            handle.rotation.z = Math.PI;
            handle.rotation.x = Math.PI / 2;
            handle.position.set(0, 0.38, -0.62);
            group.add(handle);

            // Pernas + rodas
            var legGeo = new THREE.CylinderGeometry(0.035, 0.035, 0.4, 6);
            var wheelGeo = new THREE.CylinderGeometry(0.16, 0.16, 0.09, 14);
            var wheelPositions = [
                [-0.6, -0.42, 0.35], [0.6, -0.42, 0.35],
                [-0.6, -0.42, -0.45], [0.6, -0.42, -0.45]
            ];
            wheelPositions.forEach(function (pos, i) {
                var leg = new THREE.Mesh(legGeo, frameMat);
                leg.position.set(pos[0], -0.62, pos[2]);
                group.add(leg);

                var wheel = new THREE.Mesh(wheelGeo, frameMat);
                wheel.rotation.x = Math.PI / 2;
                wheel.position.set(pos[0], -0.82, pos[2]);
                group.add(wheel);
                wheels.push(wheel);
            });

            group.scale.setScalar(1.05);
            return group;
        }

        function init(canvasEl) {
            if (!HAS_THREE || !canvasEl) return false;
            canvas = canvasEl;

            try {
                renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
            } catch (e) {
                return false;
            }
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

            scene = new THREE.Scene();
            camera = new THREE.PerspectiveCamera(32, 1, 0.1, 10);
            camera.position.set(0, 0.15, 3.1);
            camera.lookAt(0, -0.05, 0);

            ambient = new THREE.AmbientLight(0xffffff, 0.75);
            scene.add(ambient);
            key = new THREE.DirectionalLight(0xffffff, 0.9);
            key.position.set(1.5, 2, 2);
            scene.add(key);

            cartGroup = buildCart();
            cartGroup.rotation.y = -0.5;
            scene.add(cartGroup);

            resize();
            window.addEventListener('resize', resize);

            canvas.parentElement.addEventListener('pointermove', onPointerMove);
            canvas.parentElement.addEventListener('pointerleave', onPointerLeave);

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) stop(); else start();
            });

            start();
            return true;
        }

        function resize() {
            if (!renderer) return;
            var size = canvas.clientWidth || 30;
            renderer.setSize(size, size, false);
            camera.aspect = 1;
            camera.updateProjectionMatrix();
        }

        function onPointerMove(e) {
            var rect = canvas.parentElement.getBoundingClientRect();
            var relX = (e.clientX - rect.left) / rect.width - 0.5;
            var relY = (e.clientY - rect.top) / rect.height - 0.5;
            targetRotY = -0.5 + relX * 1.4;
            targetRotX = relY * 0.8;
        }

        function onPointerLeave() {
            targetRotY = -0.5;
            targetRotX = 0;
        }

        var t = 0;
        function tick() {
            if (!running) return;
            t += 0.016;
            if (!reduceMotion) {
                cartGroup.rotation.y += (targetRotY - cartGroup.rotation.y) * 0.08;
                cartGroup.rotation.x += (targetRotX - cartGroup.rotation.x) * 0.08;
                cartGroup.position.y = Math.sin(t * 1.6) * 0.035;
            }
            renderer.render(scene, camera);
            raf = requestAnimationFrame(tick);
        }

        function start() {
            if (running || !renderer) return;
            running = true;
            raf = requestAnimationFrame(tick);
        }
        function stop() {
            running = false;
            if (raf) cancelAnimationFrame(raf);
        }

        // Reação ao adicionar item: giro extra, "bounce" e brilho emissivo.
        function pulse() {
            if (!cartGroup) return;
            var glow = canvas && canvas.closest('.cart-trigger');
            if (glow) {
                glow.classList.add('pulse');
                setTimeout(function () { glow.classList.remove('pulse'); }, 650);
            }
            if (HAS_GSAP && !reduceMotion) {
                gsap.killTweensOf(cartGroup.scale);
                gsap.killTweensOf(cartGroup.rotation);
                gsap.timeline()
                    .to(cartGroup.scale, { x: 1.28, y: 1.28, z: 1.28, duration: 0.18, ease: 'power2.out' }, 0)
                    .to(cartGroup.scale, { x: 1.05, y: 1.05, z: 1.05, duration: 0.5, ease: 'elastic.out(1, 0.4)' }, 0.18)
                    .to(cartGroup.rotation, { y: cartGroup.rotation.y + Math.PI * 2, duration: 0.7, ease: 'power2.inOut' }, 0);

                wheels.forEach(function (wheel, i) {
                    gsap.fromTo(wheel.rotation, { z: 0 }, { z: Math.PI * 4, duration: 0.7, delay: i * 0.02, ease: 'power2.out' });
                });

                if (basketMat) {
                    gsap.fromTo(basketMat, { emissiveIntensity: 0 }, { emissiveIntensity: 0, duration: 0.01 });
                    basketMat.emissive.set(cssVarColor('--sky', '#7BA7D8'));
                    gsap.timeline()
                        .to(basketMat, { emissiveIntensity: 0.55, duration: 0.15 })
                        .to(basketMat, { emissiveIntensity: 0, duration: 0.5 });
                }
            } else if (cartGroup) {
                // Fallback sem GSAP: pequena animação manual.
                var start = performance.now();
                (function bump() {
                    var p = Math.min((performance.now() - start) / 300, 1);
                    var s = 1 + Math.sin(p * Math.PI) * 0.25;
                    cartGroup.scale.setScalar(s);
                    if (p < 1) requestAnimationFrame(bump);
                })();
            }
        }

        return { init: init, pulse: pulse, isReady: function () { return !!renderer; } };
    })();

    // ── 2. Peça 3D decorativa do hero ──────────────────────────────────────
    var HeroScene3D = (function () {
        function init(canvasEl) {
            if (!HAS_THREE || !canvasEl) return false;

            var renderer;
            try {
                renderer = new THREE.WebGLRenderer({ canvas: canvasEl, alpha: true, antialias: true });
            } catch (e) {
                return false;
            }
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

            var scene = new THREE.Scene();
            var camera = new THREE.PerspectiveCamera(38, 1, 0.1, 20);
            camera.position.set(0, 0.4, 5.2);

            scene.add(new THREE.AmbientLight(0xffffff, 0.8));
            var d1 = new THREE.DirectionalLight(0xffffff, 0.9);
            d1.position.set(2, 3, 4);
            scene.add(d1);

            var sky = cssVarColor('--sky', '#7BA7D8');
            var sPale = cssVarColor('--sky-pale', '#C8DDEF');
            var indigo = cssVarColor('--indigo', '#3D4A6B');

            var group = new THREE.Group();

            // Caixas de "produtos" flutuando em órbita leve — remete a comparar
            // itens de diferentes lojas, cada uma com sua própria fase.
            var boxGeo = new THREE.BoxGeometry(0.55, 0.55, 0.55);
            var boxes = [];
            var palette = [sky, sPale, indigo];
            for (var i = 0; i < 5; i++) {
                var mat = new THREE.MeshStandardMaterial({
                    color: palette[i % palette.length],
                    metalness: 0.2,
                    roughness: 0.5,
                    transparent: true,
                    opacity: 0.92
                });
                var box = new THREE.Mesh(boxGeo, mat);
                var edges = new THREE.LineSegments(
                    new THREE.EdgesGeometry(boxGeo),
                    new THREE.LineBasicMaterial({ color: indigo, transparent: true, opacity: 0.35 })
                );
                box.add(edges);
                var angle = (i / 5) * Math.PI * 2;
                var radius = 1.5;
                box.userData.baseAngle = angle;
                box.userData.radius = radius;
                box.userData.speed = 0.15 + (i % 3) * 0.05;
                box.userData.yOff = i * 0.7;
                box.scale.setScalar(0.55 + (i % 3) * 0.18);
                group.add(box);
                boxes.push(box);
            }

            // Anel indicando "comparação" / radar de preços
            var ringGeo = new THREE.TorusGeometry(1.9, 0.012, 8, 64);
            var ringMat = new THREE.MeshBasicMaterial({ color: sPale, transparent: true, opacity: 0.55 });
            var ring = new THREE.Mesh(ringGeo, ringMat);
            ring.rotation.x = Math.PI / 2.4;
            group.add(ring);

            scene.add(group);

            function resize() {
                var size = canvasEl.clientWidth || 240;
                renderer.setSize(size, size, false);
                camera.aspect = 1;
                camera.updateProjectionMatrix();
            }
            resize();
            window.addEventListener('resize', resize);

            var t = 0;
            var running = true;
            document.addEventListener('visibilitychange', function () {
                running = !document.hidden;
            });

            function tick() {
                if (running && !reduceMotion) {
                    t += 0.012;
                    boxes.forEach(function (box) {
                        var a = box.userData.baseAngle + t * box.userData.speed;
                        box.position.x = Math.cos(a) * box.userData.radius;
                        box.position.z = Math.sin(a) * box.userData.radius * 0.6;
                        box.position.y = Math.sin(t * 0.8 + box.userData.yOff) * 0.4;
                        box.rotation.x += 0.006;
                        box.rotation.y += 0.008;
                    });
                    group.rotation.y = t * 0.08;
                    ring.rotation.z += 0.0015;
                }
                renderer.render(scene, camera);
                requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
            return true;
        }
        return { init: init };
    })();

    // ── 3. Animação "voar para o carrinho" ─────────────────────────────────
    function flyToCart(sourceEl, imgSrc, targetEl) {
        if (!sourceEl || !targetEl) return;
        var startRect = sourceEl.getBoundingClientRect();
        var endRect = targetEl.getBoundingClientRect();

        var clone = document.createElement('div');
        clone.className = 'fly-to-cart';
        if (imgSrc) {
            var img = document.createElement('img');
            img.src = imgSrc;
            img.alt = '';
            clone.appendChild(img);
        } else {
            var dot = document.createElement('span');
            dot.className = 'fly-to-cart__dot';
            clone.appendChild(dot);
        }
        document.body.appendChild(clone);

        var startX = startRect.left + startRect.width / 2 - 15;
        var startY = startRect.top + startRect.height / 2 - 15;
        var endX = endRect.left + endRect.width / 2 - 15;
        var endY = endRect.top + endRect.height / 2 - 15;
        // Ponto de controle da curva — sobe antes de convergir ao carrinho.
        var ctrlX = (startX + endX) / 2;
        var ctrlY = Math.min(startY, endY) - 140;

        function place(x, y, scale, opacity, rot) {
            clone.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + scale + ') rotate(' + rot + 'deg)';
            clone.style.opacity = opacity;
        }
        place(startX, startY, 1, 1, 0);

        function finish() {
            clone.remove();
            CartIcon3D.pulse();
        }

        if (HAS_GSAP && !reduceMotion) {
            var proxy = { t: 0 };
            gsap.to(proxy, {
                t: 1,
                duration: 0.75,
                ease: 'power1.in',
                onUpdate: function () {
                    var tt = proxy.t;
                    var x = (1 - tt) * (1 - tt) * startX + 2 * (1 - tt) * tt * ctrlX + tt * tt * endX;
                    var y = (1 - tt) * (1 - tt) * startY + 2 * (1 - tt) * tt * ctrlY + tt * tt * endY;
                    var scale = 1 - tt * 0.65;
                    var opacity = tt > 0.75 ? 1 - (tt - 0.75) / 0.25 : 1;
                    place(x, y, Math.max(scale, 0.25), Math.max(opacity, 0), tt * 340);
                },
                onComplete: finish
            });
        } else {
            // Fallback via CSS transition simples caso GSAP não esteja disponível.
            clone.style.transition = 'transform .6s ease-in, opacity .6s ease-in';
            requestAnimationFrame(function () {
                place(endX, endY, 0.3, 0, 300);
            });
            setTimeout(finish, 620);
        }
    }

    // ── 4. Tilt 3D nos cards de produto ─────────────────────────────────────
    function enableCardTilt(scope) {
        if (reduceMotion || !window.matchMedia('(hover: hover)').matches) return;
        var cards = (scope || document).querySelectorAll('.product-card');
        cards.forEach(function (card) {
            if (card._tiltBound) return;
            card._tiltBound = true;

            card.addEventListener('mousemove', function (e) {
                var rect = card.getBoundingClientRect();
                var relX = (e.clientX - rect.left) / rect.width - 0.5;
                var relY = (e.clientY - rect.top) / rect.height - 0.5;
                var rotY = relX * 6;
                var rotX = -relY * 6;
                card.classList.add('tilting');
                card.style.transform = 'perspective(900px) rotateX(' + rotX + 'deg) rotateY(' + rotY + 'deg) translateY(-3px) translateZ(0)';
            });

            card.addEventListener('mouseleave', function () {
                card.classList.remove('tilting');
                card.style.transform = '';
            });
        });
    }

    // ── Inicialização ────────────────────────────────────────────────────────
    function boot() {
        var navCanvas = document.getElementById('cart3d-canvas');
        var trigger = document.getElementById('cart-trigger');
        if (trigger) {
            var ok = HAS_THREE && CartIcon3D.init(navCanvas);
            trigger.classList.add(ok ? 'cart3d-ready' : 'cart3d-unavailable');
        }

        var heroCanvas = document.getElementById('hero3d-canvas');
        if (heroCanvas && window.innerWidth > 1180) {
            HAS_THREE && HeroScene3D.init(heroCanvas);
        }

        enableCardTilt(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // API pública usada pelo script inline de home.php
    window.Cart3D = {
        pulse: function () { CartIcon3D.pulse(); },
        flyToCart: function (sourceEl, imgSrc) {
            var target = document.getElementById('cart-trigger');
            flyToCart(sourceEl, imgSrc, target);
        },
        refreshTilt: function (scope) { enableCardTilt(scope); }
    };
})();
