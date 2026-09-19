const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\2b7da38b-112d-4c2c-b3c4-c1476b6fdd55';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_profile_3d_test');

async function test3DModal() {
    console.log("Démarrage Edge pour test ouverture modale 3D...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9899",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1440,1100",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1500));

    try {
        const targets = await new Promise((resolve, reject) => {
            http.get('http://localhost:9899/json', (res) => {
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

        console.log("Navigation vers evenement id=1...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=1' });
        await new Promise(r => setTimeout(r, 2000));

        console.log("Appel de openClient3DSeating(null, 1)...");
        const evalRes = await send('Runtime.evaluate', {
            expression: `(async () => {
                if (typeof window.openClient3DSeating === 'function') {
                    await window.openClient3DSeating(null, 1);
                    return "openClient3DSeating called";
                }
                return "openClient3DSeating not found";
            })()`,
            awaitPromise: true
        });
        console.log("Résultat évaluation :", evalRes);

        await new Promise(r => setTimeout(r, 3000));

        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_3d_modal.png'), Buffer.from(shot.data, 'base64'));
        console.log("Capture modale 3D enregistrée : preview_3d_modal.png");

        ws.close();
    } catch (err) {
        console.error("Erreur durant le test 3D :", err);
    } finally {
        edge.kill();
    }
}

test3DModal();
