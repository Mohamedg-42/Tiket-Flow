const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ARTIFACT_DIR = 'C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\e2884e1f-daff-4bab-9f2d-c88ba88adc2f';
const EDGE_PATH = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const USER_DATA_DIR = path.join(ARTIFACT_DIR, 'scratch', 'edge_preview_profile');

async function captureCheckout() {
    console.log("Capture du formulaire de paiement du billet...");
    const edge = spawn(EDGE_PATH, [
        "--headless=new",
        "--remote-debugging-port=9892",
        `--user-data-dir=${USER_DATA_DIR}`,
        "--window-size=1280,950",
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

        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement/concert-geant-abidjan-live-2026#billets' });
        await new Promise(r => setTimeout(r, 2000));

        // Sélectionner un ticket pour que le panier s'affiche
        await send('Runtime.evaluate', {
            expression: `(() => {
                const firstPlus = document.querySelector('.qty-btn-plus');
                if (firstPlus) firstPlus.click();
                const checkoutBox = document.getElementById('checkoutSummaryBox');
                if (checkoutBox) checkoutBox.scrollIntoView({ behavior: 'instant', block: 'center' });
            })()`
        });

        await new Promise(r => setTimeout(r, 1000));

        const shot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'preview_checkout_facultatif_email.png'), Buffer.from(shot.data, 'base64'));

        ws.close();
        console.log("Capture enregistrée avec succès : preview_checkout_facultatif_email.png");
    } catch (err) {
        console.error("Erreur durant la capture :", err);
    } finally {
        edge.kill();
    }
}

captureCheckout();
