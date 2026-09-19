const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    await page.goto('http://localhost/ticket-platform/client/accueil', { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'C:/Users/HP/.gemini/antigravity-ide/brain/e2884e1f-daff-4bab-9f2d-c88ba88adc2f/preview_accueil_bulles_categories.png', clip: { x: 0, y: 350, width: 1280, height: 450 } });
    console.log('Screenshot saved successfully.');
    await browser.close();
})();
