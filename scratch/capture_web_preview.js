const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

if (!fs.existsSync(path.join(ARTIFACT_DIR, 'scratch'))) {
    fs.mkdirSync(path.join(ARTIFACT_DIR, 'scratch'), { recursive: true });
}

async function capturePreviews() {
    console.log("Démarrage de Microsoft Edge pour l'aperçu web...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9888",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9888/json', (res) => {
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

        // 1. Accueil Desktop
        console.log("Capture de l'accueil client (Desktop)...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil' });
        await new Promise(r => setTimeout(r, 2000));
        const shot1 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_accueil_desktop.png'), Buffer.from(shot1.data, 'base64'));

        // 2. Événement Page (Héro)
        console.log("Capture de la fiche événement (Héro & Billets)...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement/miss-princesse-akwaba' });
        await new Promise(r => setTimeout(r, 2000));
        const shot2 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_evenement_hero.png'), Buffer.from(shot2.data, 'base64'));

        // 3. Clic / Défilement sur Réserver mes Billets (#billets)
        console.log("Test du défilement fluide vers #billets...");
        await send('Runtime.evaluate', { expression: "document.getElementById('billets')?.scrollIntoView({behavior:'instant', block:'start'})" });
        await new Promise(r => setTimeout(r, 1000));
        const shot3 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_evenement_billets.png'), Buffer.from(shot3.data, 'base64'));

        // 4. Version Mobile (390 x 844)
        console.log("Capture de la version Mobile...");
        await send('Emulation.setDeviceMetricsOverride', {
            width: 390,
            height: 844,
            deviceScaleFactor: 2,
            mobile: true
        });
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil' });
        await new Promise(r => setTimeout(r, 2000));
        const shot4 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_accueil_mobile.png'), Buffer.from(shot4.data, 'base64'));

        ws.close();
        console.log("Toutes les captures d'aperçu web ont été enregistrées avec succès dans l'annuaire d'artefacts !");
    } catch (err) {
        console.error("Erreur durant la capture :", err);
    } finally {
        edge.kill();
    }
}

capturePreviews();
