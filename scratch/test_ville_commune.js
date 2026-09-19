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
        "--remote-debugging-port=9891",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1200,1000",
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

        // Navigation vers devenir-promoteur
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/devenir-promoteur' });
        await new Promise(r => setTimeout(r, 2000));

        // Test d'interaction : vérifier options de ville et commune
        const evalRes = await send('Runtime.evaluate', {
            expression: `(() => {
                const v = document.getElementById('ville');
                const c = document.getElementById('commune');
                return {
                    villeVal: v ? v.value : null,
                    villeOptions: v ? Array.from(v.options).map(o => o.value) : [],
                    communeVal: c ? c.value : null,
                    communeOptions: c ? Array.from(c.options).map(o => o.value) : []
                };
            })()`,
            returnByValue: true
        });
        console.log("État initial des selects :", JSON.stringify(evalRes.result.value, null, 2));

        // Scroll to Ville and capture screenshot
        await send('Runtime.evaluate', {
            expression: `document.getElementById('ville').scrollIntoView({ behavior: 'instant', block: 'center' });`
        });
        await new Promise(r => setTimeout(r, 500));

        const snap1 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_devenir_promoteur_ville_commune.png'), Buffer.from(snap1.data, 'base64'));
        console.log("Capture 1 enregistrée : preview_devenir_promoteur_ville_commune.png");

        // Changer la ville en Bouaké
        await send('Runtime.evaluate', {
            expression: `(() => {
                const v = document.getElementById('ville');
                v.value = 'Bouaké';
                v.dispatchEvent(new Event('change'));
            })()`
        });
        await new Promise(r => setTimeout(r, 500));

        const evalBouake = await send('Runtime.evaluate', {
            expression: `(() => {
                const c = document.getElementById('commune');
                return {
                    communeOptions: c ? Array.from(c.options).map(o => o.value) : []
                };
            })()`,
            returnByValue: true
        });
        console.log("Communes de Bouaké après sélection :", JSON.stringify(evalBouake.result.value, null, 2));

        const snap2 = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_bouake_communes.png'), Buffer.from(snap2.data, 'base64'));
        console.log("Capture 2 enregistrée : preview_bouake_communes.png");

        ws.close();
    } catch (err) {
        console.error("Erreur durant le test :", err);
    } finally {
        edge.kill();
        process.exit(0);
    }
}

run();
