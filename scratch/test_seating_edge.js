const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function testSeating() {
    console.log("1. Starting Edge headless...");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9444",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\7843ed0f-3152-4a49-af9a-588eeb9fddd3\\scratch\\edge_profile",
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1200));

    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9444/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    console.log("Targets available:", targets);
    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    const wsUrl = pageTarget?.webSocketDebuggerUrl;
    console.log("2. Connecting to WebSocket:", wsUrl);

    // Node 18+ has global WebSocket
    const ws = new WebSocket(wsUrl);

    let id = 1;
    const pending = new Map();
    const consoleLogs = [];

    function send(method, params = {}) {
        const reqId = id++;
        return new Promise((resolve) => {
            pending.set(reqId, resolve);
            ws.send(JSON.stringify({ id: reqId, method, params }));
        });
    }

    const reqMap = new Map();

    ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Runtime.consoleAPICalled') {
            consoleLogs.push({ type: msg.params.type, args: msg.params.args.map(a => a.value || a.description) });
        }
        if (msg.method === 'Runtime.exceptionThrown') {
            consoleLogs.push({ type: 'EXCEPTION', text: msg.params.exceptionDetails.text, exception: msg.params.exceptionDetails.exception });
        }
        if (msg.method === 'Network.requestWillBeSent') {
            reqMap.set(msg.params.requestId, msg.params.request.url);
        }
        if (msg.method === 'Network.responseReceived') {
            const url = msg.params.response.url;
            console.log(`[HTTP ${msg.params.response.status}] ${url}`);
        }
        if (msg.method === 'Network.loadingFailed') {
            const url = reqMap.get(msg.params.requestId);
            console.log(`[FAILED] ${msg.params.errorText} -> ${url}`);
        }
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg.result);
            pending.delete(msg.id);
        }
    };

    await new Promise(r => ws.onopen = r);

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    console.log("3. Navigating to http://localhost/ticket-platform/client/evenement.php?id=10 ...");
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=10' });

    // Wait 2.5s for page to load
    await new Promise(r => setTimeout(r, 2500));

    console.log("4. Calling openClient3DSeating(null, 10) in browser...");
    const callRes = await send('Runtime.evaluate', {
        expression: `
            (async () => {
                try {
                    console.log('Testing openClient3DSeating...');
                    await openClient3DSeating(null, 10);
                    return { success: true };
                } catch(e) {
                    console.error('Call error:', e.message, e.stack);
                    return { error: e.message, stack: e.stack };
                }
            })()
        `,
        awaitPromise: true,
        returnByValue: true
    });

    console.log("Call result:", JSON.stringify(callRes));

    // Wait 2 seconds for 3D load and render
    await new Promise(r => setTimeout(r, 2000));

    // Inspect DOM and 3D engine state
    const domState = await send('Runtime.evaluate', {
        expression: `
            (() => {
                const modal = document.getElementById('client3DSeatingModal');
                const canvas = document.getElementById('client3DCanvas');
                const tariffBar = document.getElementById('client3DTariffBar');
                return {
                    modalHidden: modal?.hidden,
                    modalDisplay: modal ? window.getComputedStyle(modal).display : null,
                    modalRect: modal ? { width: modal.offsetWidth, height: modal.offsetHeight } : null,
                    canvasRect: canvas ? { width: canvas.offsetWidth, height: canvas.offsetHeight, clientWidth: canvas.clientWidth, clientHeight: canvas.clientHeight } : null,
                    canvasSizeAttrs: canvas ? { width: canvas.width, height: canvas.height } : null,
                    tariffBarButtons: tariffBar?.querySelectorAll('button')?.length,
                    tariffBarHtml: tariffBar?.innerHTML?.substring(0, 300),
                    engineInitialized: !!window.client3DEngine,
                    engineSeatsCount: window.client3DEngine?.seats?.length || 0,
                    engineWidth: window.client3DEngine?.width,
                    engineHeight: window.client3DEngine?.height,
                    engineIsMobile: window.client3DEngine?.isMobile
                };
            })()
        `,
        returnByValue: true
    });

    console.log("5. DOM and 3D State:", JSON.stringify(domState?.result?.value, null, 2));

    // Select 2 seats to test animation, sightlines, and bursts
    await send('Runtime.evaluate', {
        expression: `
            (() => {
                if (window.client3DEngine && window.client3DEngine.seats.length > 5) {
                    const freeSeats = window.client3DEngine.seats.filter(s => s.statut === 'libre');
                    if (freeSeats[0]) window.client3DEngine.toggleSeatSelection(freeSeats[0]);
                    if (freeSeats[3]) window.client3DEngine.toggleSeatSelection(freeSeats[3]);
                }
            })()
        `
    });

    await new Promise(r => setTimeout(r, 600));

    // Capture screenshot with selected seats and animated sightlines
    const screenshot = await send('Page.captureScreenshot', { format: 'png' });
    if (screenshot?.data) {
        fs.writeFileSync('scratch/modal_screenshot.png', Buffer.from(screenshot.data, 'base64'));
        console.log("6. Screenshot with selected seats saved to scratch/modal_screenshot.png");
    }

    console.log("\n--- BROWSER CONSOLE LOGS ---");
    consoleLogs.forEach(log => console.log(JSON.stringify(log)));
    console.log("----------------------------\n");

    ws.close();
    edge.kill();
}

testSeating().catch(err => {
    console.error("Test error:", err);
    process.exit(1);
});
