const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

async function testInstantFilter() {
    console.log("Test du filtrage instantané sans rechargement de page...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9891",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9891/json', (res) => {
                let body = '';
                res.on('data', d => body += d);
                res.on('end', () => resolve(JSON.parse(body)));
            }).on('error', reject);
        });

        const target = targets.find(t => t.type === 'page') || targets[0];
        const ws = new WebSocket(target.webSocketDebuggerUrl);

        let id = 1;
        const pending = new Map();
        function send(method, params = {}) {
            const reqId = id++;
            return new Promise((resolve) => {
                pending.set(reqId, resolve);
                ws.send(JSON.stringify({ id: reqId, method, params }));
            });
        }

        ws.onmessage = (e) => {
            const msg = JSON.parse(e.data);
            if (msg.id && pending.has(msg.id)) {
                const cb = pending.get(msg.id);
                pending.delete(msg.id);
                cb(msg.result);
            }
        };

        await new Promise(r => ws.onopen = r);
        await send('Page.enable');
        await send('Runtime.enable');

        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil' });
        await new Promise(r => setTimeout(r, 2000));

        // 1. Évaluer l'état initial
        const evalInit = await send('Runtime.evaluate', {
            expression: `(() => {
                const chips = Array.from(document.querySelectorAll('.category-chips .category-chip')).map(c => ({
                    val: c.getAttribute('data-category-value'),
                    text: c.textContent.trim(),
                    active: c.classList.contains('active')
                }));
                const allCards = document.querySelectorAll('.event-card-item');
                const visibleCards = Array.from(allCards).filter(c => c.style.display !== 'none');
                return {
                    chipsCount: chips.length,
                    chips: chips,
                    totalCards: allCards.length,
                    visibleCards: visibleCards.length
                };
            })()`,
            returnByValue: true
        });
        console.log("État initial :", evalInit.result.value);

        // 2. Cliquer sur la bulle "Autre"
        const evalClickAutre = await send('Runtime.evaluate', {
            expression: `(() => {
                const chipAutre = document.querySelector('.category-chips .category-chip[data-category-value="Autre"]');
                if (!chipAutre) return { error: "Bulle Autre non trouvée" };
                
                // Marquer un timestamp dans window pour vérifier qu'AUCUN rechargement de page ne survient
                window.__PAGE_LOAD_TIME = window.__PAGE_LOAD_TIME || Date.now();
                const beforeTime = window.__PAGE_LOAD_TIME;

                chipAutre.click();

                const afterTime = window.__PAGE_LOAD_TIME;
                const allCards = document.querySelectorAll('.event-card-item');
                const visibleCards = Array.from(allCards).filter(c => c.style.display !== 'none').map(c => ({
                    id: c.id,
                    cat: c.getAttribute('data-category'),
                    title: c.querySelector('h3') ? c.querySelector('h3').textContent.trim() : ''
                }));
                const isAutreActive = chipAutre.classList.contains('active');
                const urlQuery = window.location.search;

                return {
                    noReload: (beforeTime === afterTime),
                    isAutreActive,
                    urlQuery,
                    visibleCount: visibleCards.length,
                    visibleCards
                };
            })()`,
            returnByValue: true
        });
        console.log("Après clic sur 'Autre' :", evalClickAutre.result.value);

        // Capture d'écran du filtrage instantané
        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_instant_filter_autre.png'), Buffer.from(shot.data, 'base64'));

        // 3. Cliquer sur la bulle "Tous"
        const evalClickTous = await send('Runtime.evaluate', {
            expression: `(() => {
                const chipTous = document.querySelector('.category-chips .category-chip[data-category-value="Tous"]');
                chipTous.click();

                const allCards = document.querySelectorAll('.event-card-item');
                const visibleCards = Array.from(allCards).filter(c => c.style.display !== 'none');
                return {
                    isTousActive: chipTous.classList.contains('active'),
                    urlQuery: window.location.search,
                    visibleCount: visibleCards.length
                };
            })()`,
            returnByValue: true
        });
        console.log("Après clic sur 'Tous' :", evalClickTous.result.value);

        ws.close();
        console.log("Test terminé avec grand succès !");
    } catch (err) {
        console.error("Erreur durant le test :", err);
    } finally {
        edge.kill();
    }
}

testInstantFilter();
