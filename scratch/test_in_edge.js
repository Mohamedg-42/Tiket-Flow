const { exec, spawn } = require('child_process');
const http = require('http');

async function run() {
    console.log("Starting Edge headless with remote debugging...");
    const edgeProcess = spawn("C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe", [
        "--headless",
        "--remote-debugging-port=9333",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        "about:blank"
    ]);

    // Wait 1.5 seconds for Edge to start
    await new Promise(r => setTimeout(r, 1500));

    // Get list of targets
    const targets = await new Promise((resolve, reject) => {
        http.get('http://localhost:9333/json', (res) => {
            let body = '';
            res.on('data', d => body += d);
            res.on('end', () => resolve(JSON.parse(body)));
        }).on('error', reject);
    });

    console.log("Target found:", targets[0]?.webSocketDebuggerUrl);
    edgeProcess.kill();
}

run().catch(err => {
    console.error("Error:", err);
    process.exit(1);
});
