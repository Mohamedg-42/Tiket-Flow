const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\2b7da38b-112d-4c2c-b3c4-c1476b6fdd55';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_profile_amphi');

async function captureAmphi() {
    console.log("Démarrage Edge pour capture de l'amphithéâtre SVG...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9893",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1200,850",
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

        const fileUrl = 'file:///' + path.resolve(__dirname, 'test_amphi.html').replace(/\\/g, '/');
        console.log("Navigation vers", fileUrl);
        await send('Page.navigate', { url: fileUrl });
        await new Promise(r => setTimeout(r, 1500));

        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'test_amphi_preview.png'), Buffer.from(shot.data, 'base64'));
        console.log("Capture enregistrée : test_amphi_preview.png");

        ws.close();
    } catch (err) {
        console.error("Erreur durant la capture :", err);
    } finally {
        edge.kill();
    }
}

captureAmphi();
