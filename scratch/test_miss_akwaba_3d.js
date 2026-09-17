const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function testMissAkwaba() {
    console.log("1. Starting Edge headless...");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9455",
        "--user-data-dir=" + __dirname + "\\edge_profile_akwaba",
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    let targets = null;
    for (let i = 0; i < 15; i++) {
        await new Promise(r => setTimeout(r, 600));
        try {
            targets = await new Promise((resolve, reject) => {
                http.get('http://127.0.0.1:9455/json', (res) => {
                    let body = '';
                    res.on('data', d => body += d);
                    res.on('end', () => resolve(JSON.parse(body)));
                }).on('error', reject);
            });
            if (targets && targets.length > 0) break;
        } catch (_) {}
    }

    try {
        const pageTarget = targets.find(t => t.type === 'page') || targets[0];
        const wsUrl = pageTarget?.webSocketDebuggerUrl;
        console.log("2. Connected to Edge CDP:", wsUrl);

        const ws = new WebSocket(wsUrl);
        await new Promise(r => ws.onopen = r);

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

        ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && pending.has(msg.id)) {
                const resolver = pending.get(msg.id);
                pending.delete(msg.id);
                resolver(msg.result);
            }
            if (msg.method === 'Runtime.consoleAPICalled') {
                console.log(`[BROWSER LOG (${msg.params.type})]:`, ...msg.params.args.map(a => a.value || a.description || ''));
            }
            if (msg.method === 'Runtime.exceptionThrown') {
                console.error('[BROWSER EXCEPTION]:', msg.params.exceptionDetails);
            }
        };

        await send('Runtime.enable');
        await send('Page.enable');

        console.log("3. Navigating to http://localhost/ticket-platform/client/evenement/miss-princesse-akwaba ...");
        await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement/miss-princesse-akwaba' });

        await new Promise(r => setTimeout(r, 3000));

        console.log("4. Calling openClient3DSeating() via button click or JS...");
        const clickRes = await send('Runtime.evaluate', {
            expression: `
                (async () => {
                    const btn = document.querySelector('button.btn-secondary-share[onclick*="openClient3DSeating"]');
                    if (btn) {
                        console.log('Clicking button:', btn.textContent.trim());
                        btn.click();
                        return { clicked: true, text: btn.textContent.trim() };
                    } else {
                        console.log('Calling openClient3DSeating() directly...');
                        await openClient3DSeating(null, window.EV_EVENT_ID || 10);
                        return { clicked: false, directCall: true };
                    }
                })()
            `,
            awaitPromise: true,
            returnByValue: true
        });
        console.log("Click result:", clickRes);

        // Wait 3 seconds for async 3D load and render
        await new Promise(r => setTimeout(r, 3000));

        // Capture screenshot
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        if (screenshot && screenshot.data) {
            fs.writeFileSync(__dirname + '/akwaba_seating_screenshot.png', Buffer.from(screenshot.data, 'base64'));
            console.log("Screenshot saved to scratch/akwaba_seating_screenshot.png");
        }

        // Check DOM and Canvas state
        const state = await send('Runtime.evaluate', {
            expression: `
                (() => {
                    const counts = {};
                    if (window.client3DEngine?.seats) {
                        window.client3DEngine.seats.forEach(s => {
                            const k = s.ticket_nom + ' (' + s.prix + ' F)';
                            counts[k] = (counts[k] || 0) + 1;
                        });
                    }
                    return {
                        modalVisible: !document.getElementById('client3DSeatingModal')?.hidden,
                        modalDisplay: document.getElementById('client3DSeatingModal')?.style.display,
                        canvasWidth: document.getElementById('client3DCanvas')?.width,
                        canvasHeight: document.getElementById('client3DCanvas')?.height,
                        tariffBarHTML: document.getElementById('client3DTariffBar')?.innerHTML,
                        legendHTML: document.querySelector('#client3DSeatingModal .s3d-legend')?.innerHTML,
                        engineExists: !!window.client3DEngine,
                        engineSeatsCount: window.client3DEngine?.seats?.length || 0,
                        seatsByTariff: counts
                    };
                })()
            `,
            returnByValue: true
        });
        console.log("State:", JSON.stringify(state.result?.value, null, 2));

        // Test clicking STANDARD filter button and selecting a seat
        await send('Runtime.evaluate', {
            expression: `
                (() => {
                    const stdBtn = document.querySelector('button[data-tier-id="17"]');
                    if (stdBtn) stdBtn.click();
                    const stdSeat = window.client3DEngine?.seats?.find(s => s.ticket_nom === 'STANDARD' && s.statut === 'libre');
                    if (stdSeat) window.client3DEngine.toggleSeatSelection(stdSeat);
                })()
            `
        });

        await new Promise(r => setTimeout(r, 1000));
        const screenshot2 = await send('Page.captureScreenshot', { format: 'png' });
        if (screenshot2 && screenshot2.data) {
            fs.writeFileSync(__dirname + '/akwaba_standard_selected.png', Buffer.from(screenshot2.data, 'base64'));
            console.log("Screenshot saved to scratch/akwaba_standard_selected.png");
        }

        ws.close();
    } finally {
        edge.kill();
    }
}

testMissAkwaba().catch(console.error);
