const { spawn } = require('child_process');
const http = require('http');

async function test() {
    const edge = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
        '--headless',
        '--remote-debugging-port=9446',
        '--user-data-dir=C:\\Users\\HP\\.gemini\\antigravity-ide\\brain\\8ec3b414-7b0b-4843-a7ea-3e0daadff839\\scratch\\edge_eval_profile',
        '--window-size=375,720',
        '--disable-gpu',
        '--no-first-run',
        'about:blank'
    ]);
    await new Promise(r => setTimeout(r, 1200));
    const targets = await new Promise((res, rej) => {
        http.get('http://localhost:9446/json', r => {
            let b = '';
            r.on('data', d => b += d);
            r.on('end', () => res(JSON.parse(b)));
        }).on('error', rej);
    });
    const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
    let id = 1;
    const pending = new Map();
    function send(method, params = {}) {
        const reqId = id++;
        return new Promise(r => {
            pending.set(reqId, r);
            ws.send(JSON.stringify({ id: reqId, method, params }));
        });
    }
    ws.onmessage = e => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) {
            pending.get(m.id)(m.result);
            pending.delete(m.id);
        }
    };
    await new Promise(r => ws.onopen = r);
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Page.navigate', { url: 'http://localhost/ticket-platform/client/accueil.php' });
    await new Promise(r => setTimeout(r, 1500));
    await send('Runtime.evaluate', { expression: 'document.getElementById("mobile-menu-toggle").click()' });
    const info = await send('Runtime.evaluate', {
        expression: `
            (() => {
                const nav = document.querySelector(".client-nav");
                const h = document.querySelector(".client-header");
                return {
                    windowW: window.innerWidth,
                    navW: nav.offsetWidth,
                    navRect: nav.getBoundingClientRect(),
                    headerW: h.offsetWidth,
                    headerRect: h.getBoundingClientRect(),
                    navStyles: {
                        position: window.getComputedStyle(nav).position,
                        left: window.getComputedStyle(nav).left,
                        right: window.getComputedStyle(nav).right,
                        width: window.getComputedStyle(nav).width,
                        maxWidth: window.getComputedStyle(nav).maxWidth
                    }
                };
            })()
        `,
        returnByValue: true
    });
    console.log('STYLES:', info.result.value.navStyles);
    ws.close();
    edge.kill();
}
test();
