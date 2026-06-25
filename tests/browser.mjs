/**
 * Layer 3: real browser (Playwright + Firefox). Drives each host's own submit
 * with JS on (must pass, with the host's spinner/message) and JS off (gate must
 * block), plus host-transparency cases. Reads config from process.env (run.sh).
 */
import { firefox } from 'playwright';

const B = process.env.BASE_URL || 'http://127.0.0.1:8088';
const CF7_PAGE = process.env.CF7_PAGE, WPF_PAGE = process.env.WPF_PAGE, COMMENT_POST = process.env.COMMENT_POST || '1';
const AUSER = process.env.ADMIN_USER || 'admin', APASS = process.env.ADMIN_PASS || 'admin12345';
let pass = 0, fail = 0, skip = 0;
const ok = (n, c, x = '') => { console.log(`${c ? 'PASS' : 'FAIL'}  ${n}${x ? '  (' + x + ')' : ''}`); c ? pass++ : fail++; };
const sk = (n) => { console.log(`SKIP  ${n}`); skip++; };

const browser = await firefox.launch();
const page = async (js = true) => { const ctx = await browser.newContext({ javaScriptEnabled: js }); return { ctx, p: await ctx.newPage() }; };
const terminalCf7 = (p) => p.waitForFunction(() => /(^|\s)(sent|failed|spam|invalid)(\s|$)/.test(document.querySelector('.wpcf7-form')?.className || ''), { timeout: 25000 }).catch(() => {});

console.log('== CF7 (browser) ==');
if (CF7_PAGE) {
  try {
    const { ctx, p } = await page(true);
    let status = null, posts = 0;
    p.on('response', async r => { if (r.request().method() === 'POST' && r.url().includes('/feedback') && !r.url().includes('/schema')) { posts++; try { status = (await r.json()).status; } catch {} } });
    await p.goto(`${B}/?page_id=${CF7_PAGE}`, { waitUntil: 'domcontentloaded', timeout: 40000 });
    await p.fill('.wpcf7 [name="your-name"]', 'H'); await p.fill('.wpcf7 [name="your-email"]', 'h@example.com');
    await p.fill('.wpcf7 [name="your-subject"]', 'hi'); await p.fill('.wpcf7 [name="your-message"]', 'hello');
    await p.click('.wpcf7 input[type=submit], .wpcf7 button[type=submit]', { noWaitAfter: true });
    let spun = false; try { await p.waitForFunction(() => document.querySelector('.wpcf7-form')?.classList.contains('submitting'), { timeout: 1500 }); spun = true; } catch {}
    await terminalCf7(p); await p.waitForTimeout(1000);
    const msg = await p.evaluate(() => { const o = document.querySelector('.wpcf7-response-output'); return o && getComputedStyle(o).display !== 'none' && /thank you/i.test(o.textContent); });
    ok('CF7 JS-on: spinner during solve', spun);
    ok('CF7 JS-on: mail_sent', status === 'mail_sent', 'status=' + status);
    ok('CF7 JS-on: success message visible', msg);
    ok('CF7 JS-on: exactly one feedback POST', posts === 1, posts + ' POST');
    await ctx.close();
  } catch (e) { ok('CF7 JS-on', false, String(e).slice(0, 90)); }
  try { // double-click busy
    const { ctx, p } = await page(true); let posts = 0;
    p.on('response', r => { if (r.request().method() === 'POST' && r.url().includes('/feedback') && !r.url().includes('/schema')) posts++; });
    await p.goto(`${B}/?page_id=${CF7_PAGE}`, { waitUntil: 'domcontentloaded' });
    await p.fill('.wpcf7 [name="your-name"]', 'H'); await p.fill('.wpcf7 [name="your-email"]', 'h@example.com');
    await p.fill('.wpcf7 [name="your-subject"]', 'hi'); await p.fill('.wpcf7 [name="your-message"]', 'dbl');
    const sel = '.wpcf7 input[type=submit], .wpcf7 button[type=submit]';
    await p.click(sel, { noWaitAfter: true }); await p.click(sel, { noWaitAfter: true }).catch(() => {});
    await terminalCf7(p); await p.waitForTimeout(800);
    ok('CF7 double-click -> exactly one submission', posts === 1, posts + ' POST');
    await ctx.close();
  } catch (e) { ok('CF7 double-click', false, String(e).slice(0, 90)); }
  try { // JS off: CF7 is AJAX-only, so it cannot submit; assert no proof is present
    const { ctx, p } = await page(false);
    await p.goto(`${B}/?page_id=${CF7_PAGE}`, { waitUntil: 'domcontentloaded' });
    const st = await p.evaluate(() => ({
      marker: !!document.querySelector('.wpcf7 [name="dumbouncer_gate"]'),
      proof: !!document.querySelector('.wpcf7 [name="a"]'),
    }));
    // marker is server-rendered; the proof field only exists if JS injected it.
    ok('CF7 JS-off: marker present, no proof injected (cannot pass the gate)', st.marker && !st.proof);
    await ctx.close();
  } catch (e) { ok('CF7 JS-off', false, String(e).slice(0, 90)); }
} else sk('CF7 (no page configured)');

console.log('== Comments (browser) ==');
try {
  const { ctx, p } = await page(true);
  await p.goto(`${B}/?p=${COMMENT_POST}`, { waitUntil: 'domcontentloaded', timeout: 40000 });
  await p.fill('#commentform #author', 'H'); await p.fill('#commentform #email', 'h@example.com');
  await p.fill('#commentform #comment', 'browser comment ' + Date.now());
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#commentform #submit')]);
  ok('comment JS-on: accepted', /unapproved=|moderation-hash=|#comment-/.test(p.url()) && !/begins with four bytes/i.test(await p.content()), 'url=' + p.url().replace(B, '').slice(0, 36));
  await ctx.close();
} catch (e) { ok('comment JS-on', false, String(e).slice(0, 90)); }
try {
  const { ctx, p } = await page(false);
  await p.goto(`${B}/?p=${COMMENT_POST}`, { waitUntil: 'domcontentloaded' });
  await p.fill('#commentform #author', 'N'); await p.fill('#commentform #email', 'n@example.com');
  await p.fill('#commentform #comment', 'no js');
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#commentform #submit')]);
  ok('comment JS-off: blocked by gate', /begins with four bytes/i.test(await p.content()));
  await ctx.close();
} catch (e) { ok('comment JS-off', false, String(e).slice(0, 90)); }

console.log('== WPForms (browser) ==');
if (WPF_PAGE) {
  try {
    const { ctx, p } = await page(true); let success = null;
    p.on('response', async r => { if (r.request().method() === 'POST' && r.url().includes('admin-ajax')) { try { success = (await r.json()).success; } catch {} } });
    await p.goto(`${B}/?page_id=${WPF_PAGE}`, { waitUntil: 'domcontentloaded', timeout: 40000 });
    await p.fill('.wpforms-form input[type=email]', 'h@example.com');
    await p.fill('.wpforms-form input[type=text]:not([aria-hidden="true"]):not([tabindex="-1"])', 'Human');
    const ta = p.locator('.wpforms-form textarea'); if (await ta.count()) await ta.first().fill('hello');
    await p.click('.wpforms-form button[type=submit], .wpforms-form input[type=submit]', { noWaitAfter: true });
    let spun = false; try { await p.waitForFunction(() => { const s = document.querySelector('.wpforms-submit-spinner'); const b = document.querySelector('.wpforms-submit'); return s && getComputedStyle(s).display !== 'none' && b && b.disabled; }, { timeout: 1500 }); spun = true; } catch {}
    await p.waitForFunction(() => document.querySelector('.wpforms-confirmation-container, .wpforms-confirmation-container-full') !== null, { timeout: 25000 }).catch(() => {});
    const conf = await p.locator('.wpforms-confirmation-container, .wpforms-confirmation-container-full').count();
    ok('WPForms JS-on: spinner+disabled during solve', spun);
    ok('WPForms JS-on: success + confirmation', success === true && conf > 0, 'success=' + success);
    await ctx.close();
  } catch (e) { ok('WPForms JS-on', false, String(e).slice(0, 90)); }

  // Graceful degradation: if the host's UI hooks are gone (e.g. WPForms renames
  // or drops .wpforms-submit-spinner / .wpforms-submit), the gate must STILL work
  // - the form submits with a valid proof, just without the cosmetic spinner.
  try {
    const { ctx, p } = await page(true); let success = null;
    p.on('response', async r => { if (r.request().method() === 'POST' && r.url().includes('admin-ajax')) { try { success = (await r.json()).success; } catch {} } });
    await p.goto(`${B}/?page_id=${WPF_PAGE}`, { waitUntil: 'domcontentloaded', timeout: 40000 });
    // Remove only the spinner element our code reaches for - WPForms' own submit
    // still works; this isolates OUR dependency on .wpforms-submit-spinner.
    await p.evaluate(() => { document.querySelectorAll('.wpforms-submit-spinner').forEach(e => e.remove()); });
    await p.fill('.wpforms-form input[type=email]', 'h@example.com');
    await p.fill('.wpforms-form input[type=text]:not([aria-hidden="true"]):not([tabindex="-1"])', 'Human');
    const ta = p.locator('.wpforms-form textarea'); if (await ta.count()) await ta.first().fill('hello');
    await p.click('.wpforms-form button[type=submit], .wpforms-form input[type=submit]', { noWaitAfter: true });
    await p.waitForFunction(() => document.querySelector('.wpforms-confirmation-container, .wpforms-confirmation-container-full') !== null, { timeout: 25000 }).catch(() => {});
    const conf = await p.locator('.wpforms-confirmation-container, .wpforms-confirmation-container-full').count();
    ok('WPForms host UI hooks removed -> still submits with proof (graceful)', success === true && conf > 0, 'success=' + success);
    await ctx.close();
  } catch (e) { ok('WPForms graceful degradation', false, String(e).slice(0, 90)); }
} else sk('WPForms (not configured / not loaded)');

console.log('== Login + Registration (browser) ==');
try {
  const { ctx, p } = await page(true);
  await p.goto(`${B}/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 40000 });
  await p.fill('#user_login', AUSER); await p.fill('#user_pass', APASS);
  await p.waitForFunction(() => { const f = document.querySelector('#loginform [name=a]'); return f && f.value.length > 0; }, { timeout: 20000 }).catch(() => {});
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#wp-submit')]);
  ok('login JS-on correct creds -> logged in', /\/wp-admin/.test(p.url()), p.url().replace(B, '').slice(0, 24));
  await ctx.close();
} catch (e) { ok('login JS-on', false, String(e).slice(0, 90)); }
try {
  const { ctx, p } = await page(false);
  await p.goto(`${B}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await p.fill('#user_login', AUSER); await p.fill('#user_pass', APASS);
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#wp-submit')]);
  ok('login JS-off -> blocked by gate', /Proof-of-work check failed/i.test(await p.content()));
  await ctx.close();
} catch (e) { ok('login JS-off', false, String(e).slice(0, 90)); }
try {
  const { ctx, p } = await page(true); const u = 'dbo' + Date.now();
  await p.goto(`${B}/wp-login.php?action=register`, { waitUntil: 'domcontentloaded' });
  await p.fill('#user_login', u); await p.fill('#user_email', u + '@example.com');
  await p.waitForFunction(() => { const f = document.querySelector('#registerform [name=a]'); return f && f.value.length > 0; }, { timeout: 20000 }).catch(() => {});
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#wp-submit')]);
  ok('register JS-on -> success', /checkemail=registered|Registration complete/i.test(p.url() + await p.content()));
  await ctx.close();
} catch (e) { ok('register JS-on', false, String(e).slice(0, 90)); }
try {
  const { ctx, p } = await page(false); const u = 'dbojs' + Date.now();
  await p.goto(`${B}/wp-login.php?action=register`, { waitUntil: 'domcontentloaded' });
  await p.fill('#user_login', u); await p.fill('#user_email', u + '@example.com');
  await Promise.all([p.waitForNavigation({ timeout: 20000 }).catch(() => {}), p.click('#wp-submit')]);
  ok('register JS-off -> blocked by gate', /Proof-of-work check failed/i.test(await p.content()));
  await ctx.close();
} catch (e) { ok('register JS-off', false, String(e).slice(0, 90)); }

await browser.close();
console.log(`\nbrowser: ${pass} passed, ${fail} failed, ${skip} skipped`);
process.exit(fail ? 1 : 0);
