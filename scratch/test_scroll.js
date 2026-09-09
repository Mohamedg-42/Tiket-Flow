const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function scrollTest() {
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9777",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\7843ed0f-3152-4a49-af9a-588eeb9fddd3\\scratch\\edge_profile_scroll",
        "--window-size=1280,1000",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1200));

    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9777/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
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

    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil.php?_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 1500));

    // Scroll to cards
    await send('Runtime.evaluate', { expression: 'window.scrollTo(0, 700)' });
    await new Promise(r => setTimeout(r, 600));

    const shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('scratch/cards_scrolled.png', Buffer.from(shot.data, 'base64'));

    ws.close();
    edge.kill();
    process.exit(0);
}

scrollTest().catch(e => { console.error(e); process.exit(1); });
