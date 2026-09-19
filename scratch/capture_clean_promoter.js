const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

async function run() {
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9893",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1200,1000",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9893/json', (res) => {
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
        await send('Runtime.enable');

        await send('Page.navigate', { url: 'http://localhost/ticket-platform/devenir-promoteur' });
        await new Promise(r => setTimeout(r, 2000));

        const snap = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_devenir_promoteur_clean.png'), Buffer.from(snap.data, 'base64'));
        console.log("Capture propre enregistrée : preview_devenir_promoteur_clean.png");

        ws.close();
    } catch (err) {
        console.error("Erreur durant le test :", err);
    } finally {
        edge.kill();
        process.exit(0);
    }
}

run();
