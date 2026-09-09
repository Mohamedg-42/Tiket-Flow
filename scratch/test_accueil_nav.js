const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function testAccueilNav() {
    console.log("1. Starting Edge headless for Accueil Navigation Test...");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9445",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\8ec3b414-7b0b-4843-a7ea-3e0daadff839\\scratch\\edge_nav_profile",
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1200));

    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9445/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    const wsUrl = pageTarget?.webSocketDebuggerUrl;
    console.log("2. Connecting to WebSocket:", wsUrl);

    const ws = new WebSocket(wsUrl);
    let id = 1;
    const pending = new Map();

    function send(method, params = {}) {
        const reqId = id++;
        return new Promise((resolve) => {
            pending.set(reqId, resolve);
            ws.send(JSON.stringify({ id: reqId, method, params }));
        });
    }

    ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg.result);
            pending.delete(msg.id);
        }
    };

    await new Promise(r => ws.onopen = r);
    await send('Page.enable');
    await send('Runtime.enable');

    console.log("3. Navigating to Desktop Accueil...");
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil.php' });
    await new Promise(r => setTimeout(r, 2000));

    // Capture Desktop screenshot
    const scrDesktop = await send('Page.captureScreenshot', { format: 'png' });
    if (scrDesktop?.data) {
        fs.writeFileSync('scratch/desktop_accueil_nav.png', Buffer.from(scrDesktop.data, 'base64'));
        console.log("4. Desktop screenshot saved to scratch/desktop_accueil_nav.png");
    }

    // Resize to Mobile (375x720) and test hamburger toggle
    console.log("5. Testing Mobile View & Hamburger Toggle...");
    await send('Emulation.setDeviceMetricsOverride', {
        width: 375,
        height: 720,
        deviceScaleFactor: 2,
        mobile: true
    });
    await new Promise(r => setTimeout(r, 500));

    // Click mobile hamburger menu toggle
    await send('Runtime.evaluate', {
        expression: `
            (() => {
                const btn = document.getElementById('mobile-menu-toggle');
                if (btn) btn.click();
            })()
        `
    });
    await new Promise(r => setTimeout(r, 400));

    // Capture Mobile screenshot with menu open
    const scrMobile = await send('Page.captureScreenshot', { format: 'png' });
    if (scrMobile?.data) {
        fs.writeFileSync('scratch/mobile_accueil_nav.png', Buffer.from(scrMobile.data, 'base64'));
        console.log("6. Mobile menu screenshot saved to scratch/mobile_accueil_nav.png");
    }

    ws.close();
    edge.kill();
    console.log("Done!");
}

testAccueilNav().catch(err => {
    console.error("Test error:", err);
    process.exit(1);
});
