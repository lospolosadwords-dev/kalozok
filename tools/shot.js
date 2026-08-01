// Headless render + füstteszt — Playwright + Chromium (SwiftShader WebGL).
//
//   npm i playwright        # egyszer
//   python3 -m http.server 8188
//   node tools/shot.js out.png 6000 "?play=1"
//
// Kiírja a JS-hibákat, a konzol-hibákat ÉS a bukott hálózati kéréseket is —
// friss klónon mindháromnak NULLÁNAK kell lennie.
const { chromium } = require('playwright');

(async () => {
  const out = process.argv[2] || 'shot.png';
  const waitMs = parseInt(process.argv[3] || '6000', 10);
  const params = process.argv[4] || '?play=1';
  const base = process.env.SHOT_URL || 'http://127.0.0.1:8188/index.html';
  const W = parseInt(process.env.SHOT_W || '1600', 10);
  const H = parseInt(process.env.SHOT_H || '900', 10);

  // HEADED=1 → valódi GPU-val renderel (élesebb kép a README-hez); alapból headless+SwiftShader.
  const browser = await chromium.launch({
    headless: !process.env.HEADED,
    args: ['--use-gl=angle', '--use-angle=swiftshader', '--ignore-gpu-blocklist',
           '--enable-webgl', '--enable-unsafe-swiftshader', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });

  // SHOT_NOTUT=1 → a bevezető ne induljon el (tisztább képernyőkép)
  if (process.env.SHOT_NOTUT) {
    await page.addInitScript(() => { try { localStorage.setItem('kaloz_tut', '1'); } catch (e) {} });
  }

  const errors = [], failed = [];
  page.on('pageerror', e => errors.push('PAGEERR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE: ' + m.text()); });
  page.on('requestfailed', r => failed.push(r.url() + ' — ' + (r.failure() || {}).errorText));
  page.on('response', r => { if (r.status() >= 400) failed.push(r.status() + ' ' + r.url()); });

  try {
    await page.goto(base + params, { waitUntil: 'load', timeout: 45000 });
  } catch (e) { console.log('goto err', e.message); }
  await page.waitForTimeout(waitMs);

  const diag = await page.evaluate(() => {
    const c = document.querySelector('canvas');
    const gl = c && (c.getContext('webgl') || c.getContext('webgl2'));
    return {
      canvas: !!c, gl: !!gl,
      ships: (typeof enemies !== 'undefined' && enemies) ? enemies.length : -1,
      islands: (typeof islands !== 'undefined' && islands) ? islands.length : -1,
      ver: (document.getElementById('ver') || {}).textContent || null,
    };
  }).catch(e => ({ err: '' + e }));

  // A sima page.screenshot néha beragad a „waiting for fonts to load"-nál (SwiftShader lassú),
  // ezért bukásra CDP-vel kapjuk el a képet — az nem vár betűtípusra.
  try {
    await page.screenshot({ path: out, timeout: 20000 });
  } catch (e) {
    const cdp = await page.context().newCDPSession(page);
    const { data } = await cdp.send('Page.captureScreenshot', { format: 'png' });
    require('fs').writeFileSync(out, Buffer.from(data, 'base64'));
    console.log('(CDP-vel készült a kép:', e.message.split('\n')[0] + ')');
  }
  console.log('SHOT', out, JSON.stringify(diag));
  console.log('JS-hibák:', errors.length ? errors.slice(0, 8) : 'nincs');
  console.log('bukott kérések:', failed.length ? failed.slice(0, 8) : 'nincs');
  await browser.close();
  process.exit(errors.length || failed.length ? 1 : 0);
})();
