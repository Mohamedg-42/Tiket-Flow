const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function verify() {
    console.log("Starting Edge for visual verification...");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9666",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\7843ed0f-3152-4a49-af9a-588eeb9fddd3\\scratch\\edge_profile_verify",
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1200));

    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9666/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

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

    // Test 1: Evenement page
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=10&_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 1500));

    const shotEv = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('scratch/clean_evenement.png', Buffer.from(shotEv.data, 'base64'));
    console.log("Saved scratch/clean_evenement.png");

    // Test 2: Accueil page
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil.php?_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 1500));

    const shotAcc = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('scratch/clean_accueil.png', Buffer.from(shotAcc.data, 'base64'));
    console.log("Saved scratch/clean_accueil.png");

    ws.close();
    edge.kill();
    console.log("Verification complete.");
    process.exit(0);
}

verify().catch(e => {
    console.error(e);
    process.exit(1);
});
