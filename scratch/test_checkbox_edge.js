const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');

async function testCheckbox() {
    console.log("1. Starting Edge headless...");
    const edge = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9445",
        "--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\7843ed0f-3152-4a49-af9a-588eeb9fddd3\\scratch\\edge_profile2",
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

    console.log("2. Navigating to event page...");
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/evenement.php?id=10' });
    await new Promise(r => setTimeout(r, 2000));

    console.log("3. Ticking checkbox seat_toggle_17...");
    const checkRes = await send('Runtime.evaluate', {
        expression: `
            (() => {
                const cb = document.querySelector('.seat-choice-checkbox') || document.getElementById('seat_toggle_17');
                if (!cb) return { error: 'Checkbox not found' };
                cb.checked = true;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
                return { success: true, id: cb.id };
            })()
        `,
        returnByValue: true
    });
    console.log("Checkbox tick result:", checkRes?.result?.value);

    // Wait 2.5 seconds for modal opening and rendering
    await new Promise(r => setTimeout(r, 2500));

    const modalState = await send('Runtime.evaluate', {
        expression: `
            (() => {
                const modal = document.getElementById('client3DSeatingModal');
                const tariffBar = document.getElementById('client3DTariffBar');
                return {
                    hidden: modal?.hidden,
                    display: modal ? window.getComputedStyle(modal).display : null,
                    buttonsCount: tariffBar?.querySelectorAll('button')?.length,
                    activeFilter: tariffBar?.querySelector('.studio-filter-btn.active')?.textContent
                };
            })()
        `,
        returnByValue: true
    });
    console.log("Modal state after ticking checkbox:", modalState?.result?.value);

    const screenshot = await send('Page.captureScreenshot', { format: 'png' });
    if (screenshot?.data) {
        fs.writeFileSync('scratch/checkbox_modal_screenshot.png', Buffer.from(screenshot.data, 'base64'));
        console.log("Screenshot saved to scratch/checkbox_modal_screenshot.png");
    }

    ws.close();
    edge.kill();
}

testCheckbox().catch(console.error);
