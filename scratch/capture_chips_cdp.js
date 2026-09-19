const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

async function captureBulles() {
    console.log("Capture des bulles de catégories sur l'accueil...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9890",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9890/json', (res) => {
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
        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_accueil_bulles_categories.png'), Buffer.from(shot.data, 'base64'));

        // Capture aussi avec le filtre "Autre" activé
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil?categorie=Autre' });
        await new Promise(r => setTimeout(r, 2000));
        const shotAutre = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_accueil_bulle_autre_active.png'), Buffer.from(shotAutre.data, 'base64'));

        ws.close();
        console.log("Captures des bulles enregistrées avec succès.");
    } catch (err) {
        console.error("Erreur durant la capture :", err);
    } finally {
        edge.kill();
    }
}

captureBulles();
