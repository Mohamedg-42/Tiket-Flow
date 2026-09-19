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
        "--remote-debugging-port=9892",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1200,1000",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9892/json', (res) => {
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

        // Sélectionner 'Autre' pour la ville
        await send('Runtime.evaluate', {
            expression: `(() => {
                const v = document.getElementById('ville');
                v.value = 'Autre';
                v.dispatchEvent(new Event('change'));
            })()`
        });
        await new Promise(r => setTimeout(r, 500));

        // Sélectionner 'Autre commune...' pour la commune
        await send('Runtime.evaluate', {
            expression: `(() => {
                const c = document.getElementById('commune');
                c.value = 'Autre commune...';
                c.dispatchEvent(new Event('change'));
                document.getElementById('ville_autre').value = 'Gagnoa-Extension';
                document.getElementById('commune_autre').value = 'Quartier Nouveau';
            })()`
        });
        await new Promise(r => setTimeout(r, 500));

        // Scroll to Ville and capture screenshot
        await send('Runtime.evaluate', {
            expression: `document.getElementById('ville').scrollIntoView({ behavior: 'instant', block: 'center' });`
        });
        await new Promise(r => setTimeout(r, 500));

        const snap3 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_autre_ville_commune.png'), Buffer.from(snap3.data, 'base64'));
        console.log("Capture 3 enregistrée : preview_autre_ville_commune.png");

        ws.close();
    } catch (err) {
        console.error("Erreur durant le test :", err);
    } finally {
        edge.kill();
        process.exit(0);
    }
}

run();
