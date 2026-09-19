const { spawn } = require('child_process');
const http = require('http');

async function run() {
    const edge = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
        '--headless=new', '--remote-debugging-port=9898', 'about:blank'
    ]);
    await new Promise(r => setTimeout(r, 1200));
    const targets = await new Promise(res => http.get('http://localhost:9898/json', r => {
        let d = ''; r.on('data', c => d += c); r.on('end', () => res(JSON.parse(d)));
    }));
    const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
    await new Promise(r => ws.onopen = r);
    let id = 1;
    function send(m, p={}) { return new Promise(res => {
        const i = id++;
        const fn = (e) => { const d = JSON.parse(e.data); if (d.id === i) { ws.removeEventListener('message', fn); res(d.result); } };
        ws.addEventListener('message', fn);
        ws.send(JSON.stringify({id: i, method: m, params: p}));
    });}
    await send('Page.navigate', {url: 'http://localhost/ticket-platform/client/evenement.php?id=1'});
    await new Promise(r => setTimeout(r, 2000));
    
    // Check elements around x: 500, y: 340 (window is 1440x1100, .seating-stage-container is in middle)
    const info = await send('Runtime.evaluate', {
        expression: `(() => {
            const container = document.querySelector('.seating-stage-container');
            const rect = container.getBoundingClientRect();
            // find all children
            return {
                containerRect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height },
                innerHTMLSnippet: container.innerHTML.slice(0, 300)
            };
        })()`,
        returnByValue: true
    });
    console.log("Container info:", JSON.stringify(info.value, null, 2));

    edge.kill();
}
run();
