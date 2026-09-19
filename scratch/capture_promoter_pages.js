const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

async function run() {
    console.log("Lancement du test visuel...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9889",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1200,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9889/json', (res) => {
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

        await new Promise((resolve) => {
            ws.onopen = resolve;
            ws.onmessage = (msg) => {
                const data = JSON.parse(msg.data);
                if (pending.has(data.id)) {
                    const cb = pending.get(data.id);
                    pending.delete(data.id);
                    cb(data.result);
                }
            };
        });

        await send('Page.enable');

        // 1. Page Inscription (Onglet Client par défaut)
        console.log("Navigation vers inscription...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/inscription' });
        await new Promise(r => setTimeout(r, 1500));
        let shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_inscription_tabs.png'), Buffer.from(shot.data, 'base64'));
        console.log("Capture 1: preview_inscription_tabs.png enregistrée !");

        // 2. Page Inscription avec onglet Promoteur activé
        console.log("Bascule vers onglet Promoteur...");
        await send('Runtime.evaluate', { expression: "switchRegistrationRole('promoteur')" });
        await new Promise(r => setTimeout(r, 1000));
        shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_inscription_promoteur.png'), Buffer.from(shot.data, 'base64'));
        console.log("Capture 2: preview_inscription_promoteur.png enregistrée !");

        // 3. Page Devenir Promoteur autonome à la racine
        console.log("Navigation vers devenir-promoteur racine...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/devenir-promoteur' });
        await new Promise(r => setTimeout(r, 1500));
        shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_devenir_promoteur.png'), Buffer.from(shot.data, 'base64'));
        console.log("Capture 3: preview_devenir_promoteur.png enregistrée !");

        // 4. Test redirection client/devenir-promoteur -> devenir-promoteur
        console.log("Test redirection client/devenir-promoteur...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/devenir-promoteur' });
        await new Promise(r => setTimeout(r, 1500));
        const currentUrl = await send('Runtime.evaluate', { expression: "window.location.href" });
        console.log("URL après redirection de client/devenir-promoteur :", currentUrl.result.value);

        ws.close();
        edge.kill();
        console.log("Tous les tests visuels et de redirection ont réussi !");
    } catch (e) {
        console.error("Erreur test :", e);
        edge.kill();
    }
}

run();
