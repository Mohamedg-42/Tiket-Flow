const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function run() {
    console.log("=== Testing User Scenarios ===");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9555",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\7843ed0f-3152-4a49-af9a-588eeb9fddd3\\scratch\\edge_profile_test",
        "--window-size=1280,900",
        "--disable-gpu",
        "--no-first-run",
        "about:blank"
    ]);

    await new Promise(r => setTimeout(r, 1200));

    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9555/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    const pageTarget = targets.find(t => t.type === 'page') || targets[0];
    const ws = new WebSocket(pageTarget.webSocketDebuggerUrl);

    let id = 1;
    const pending = new Map();
    const errors = [];
    const logs = [];

    function send(method, params = {}) {
        const reqId = id++;
        return new Promise((resolve) => {
            pending.set(reqId, resolve);
            ws.send(JSON.stringify({ id: reqId, method, params }));
        });
    }

    ws.onmessage = (e) => {
        const msg = JSON.parse(e.data);
        if (msg.method === 'Runtime.exceptionThrown') {
            errors.push(msg.params.exceptionDetails);
            console.error("PAGE EXCEPTION:", msg.params.exceptionDetails.text, msg.params.exceptionDetails.exception?.description);
        }
        if (msg.method === 'Runtime.consoleAPICalled') {
            const txt = msg.params.args.map(a => a.value || a.description).join(' ');
            logs.push(`[${msg.params.type}] ${txt}`);
            if (msg.params.type === 'error' || msg.params.type === 'warning') {
                console.log(`CONSOLE [${msg.params.type}]:`, txt);
            }
        }
        if (msg.id && pending.has(msg.id)) {
            const cb = pending.get(msg.id);
            pending.delete(msg.id);
            cb(msg.result);
        }
    };

    await new Promise(r => ws.onopen = r);
    await send('Page.enable');
    await send('Runtime.enable');
    await send('DOM.enable');

    // TEST 1: MOBILE VIEW ON ACCUEIL.PHP
    console.log("\n--- TEST 1: Mobile on client/accueil.php ---");
    await send('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 2,
        mobile: true
    });

    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil.php?_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 2000));

    // Click on the first event card
    const cardRes = await send('Runtime.evaluate', {
        expression: `(() => {
            const card = document.querySelector('.event-card-item');
            if (!card) return 'NO CARD';
            card.click();
            return 'CLICKED CARD ' + card.id;
        })()`
    });
    console.log("Card click result:", cardRes.result?.value);
    await new Promise(r => setTimeout(r, 800));

    // Check if clientEventModal opened
    const modalCheck = await send('Runtime.evaluate', {
        expression: `(() => {
            const m = document.getElementById('clientEventModal');
            const cboxes = document.querySelectorAll('.seat-choice-block input[type="checkbox"]');
            return {
                modalOpen: m ? (!m.hidden && m.style.display !== 'none') : false,
                checkboxesCount: cboxes.length,
                checkboxIds: Array.from(cboxes).map(c => c.id)
            };
        })()`,
        returnByValue: true
    });
    console.log("Modal check after card click:", modalCheck.result?.value);

    // Now click the checkbox in the modal
    const cbClickRes = await send('Runtime.evaluate', {
        expression: `(async () => {
            const cb = document.querySelector('.seat-choice-block input[type="checkbox"]');
            if (!cb) return 'NO CB';
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(r => setTimeout(r, 1000));
            const m3d = document.getElementById('client3DSeatingModal');
            return {
                cbId: cb.id,
                m3dExists: !!m3d,
                m3dHidden: m3d ? m3d.hidden : null,
                m3dDisplay: m3d ? window.getComputedStyle(m3d).display : null,
                m3dVisibility: m3d ? window.getComputedStyle(m3d).visibility : null,
                m3dZIndex: m3d ? window.getComputedStyle(m3d).zIndex : null,
                canvasWidth: document.getElementById('client3DCanvas')?.width,
                canvasHeight: document.getElementById('client3DCanvas')?.height,
                canvasClientWidth: document.getElementById('client3DCanvas')?.clientWidth,
                canvasClientHeight: document.getElementById('client3DCanvas')?.clientHeight
            };
        })()`,
        returnByValue: true,
        awaitPromise: true
    });
    console.log("Checkbox click & 3D modal state on mobile accueil:", cbClickRes.result?.value);

    // Take screenshot of mobile 3d modal
    const shot1 = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('scratch/mobile_accueil_3d.png', Buffer.from(shot1.data, 'base64'));
    console.log("Saved scratch/mobile_accueil_3d.png");

    // TEST 2: MOBILE VIEW ON EVENEMENT.PHP?id=10
    console.log("\n--- TEST 2: Mobile on client/evenement.php?id=10 ---");
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=10&_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 2000));

    const evCheckMobile = await send('Runtime.evaluate', {
        expression: `(async () => {
            const cb = document.getElementById('seat_toggle_17');
            if (!cb) return 'NO CB 17';
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(r => setTimeout(r, 1200));
            const m3d = document.getElementById('client3DSeatingModal');
            return {
                m3dDisplay: m3d ? window.getComputedStyle(m3d).display : null,
                m3dHidden: m3d ? m3d.hidden : null,
                canvasWidth: document.getElementById('client3DCanvas')?.width,
                canvasHeight: document.getElementById('client3DCanvas')?.height,
                canvasClientW: document.getElementById('client3DCanvas')?.clientWidth,
                canvasClientH: document.getElementById('client3DCanvas')?.clientHeight,
                filterButtonsCount: document.querySelectorAll('#client3DTariffBar .studio-filter-btn').length
            };
        })()`,
        returnByValue: true,
        awaitPromise: true
    });
    console.log("Mobile evenement.php 3D state:", evCheckMobile.result?.value);

    const shot2 = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('scratch/mobile_evenement_3d.png', Buffer.from(shot2.data, 'base64'));
    console.log("Saved scratch/mobile_evenement_3d.png");

    // TEST 3: DESKTOP VIEW ON EVENEMENT.PHP?id=10
    console.log("\n--- TEST 3: Desktop on client/evenement.php?id=10 ---");
    await send('Emulation.clearDeviceMetricsOverride');
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=10&_t=' + Date.now() });
    await new Promise(r => setTimeout(r, 2000));

    const evCheckDesktop = await send('Runtime.evaluate', {
        expression: `(async () => {
            const cb = document.getElementById('seat_toggle_17');
            if (!cb) return 'NO CB 17';
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
            await new Promise(r => setTimeout(r, 1200));
            const m3d = document.getElementById('client3DSeatingModal');
            return {
                m3dDisplay: m3d ? window.getComputedStyle(m3d).display : null,
                canvasW: document.getElementById('client3DCanvas')?.clientWidth,
                canvasH: document.getElementById('client3DCanvas')?.clientHeight
            };
        })()`,
        returnByValue: true,
        awaitPromise: true
    });
    console.log("Desktop evenement.php 3D state:", evCheckDesktop.result?.value);

    console.log("\nTotal page exceptions caught:", errors.length);
    ws.close();
    edge.kill();
    process.exit(0);
}

run().catch(e => {
    console.error("FATAL TEST ERROR:", e);
    process.exit(1);
});
