import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createServer } from 'node:http';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { existsSync } from 'node:fs';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';

const browser = process.env.MCU_BROWSER_EXECUTABLE ?? join(process.env['ProgramFiles(x86)'] ?? '', 'Microsoft/Edge/Application/msedge.exe');

test('patient table and profile modals render on desktop/mobile with realtime filters, photo preview and fade transitions', { skip: !existsSync(browser), timeout: 60000 }, async (t) => {
    const output = await mkdtemp(join(process.env.LOCALAPPDATA ?? process.cwd(), 'Temp/opencode/patient-browser-'));
    const generated = spawnSync('php', ['artisan', 'test', '--compact', 'tests/Feature/PatientWorkspaceTest.php', '--filter=test_list_shows_photo_on_left'], {
        cwd: process.cwd(), env: { ...process.env, MCU_PATIENT_BROWSER_OUTPUT: output }, encoding: 'utf8', timeout: 20000,
    });
    assert.equal(generated.status, 0, generated.stdout + generated.stderr);
    const fixtures = Object.fromEntries(await Promise.all(['index', 'detail', 'edit'].map(async (name) => [name, await readFile(join(output, `${name}.html`), 'utf8')])));
    let origin, searches = 0;
    const server = createServer(async (request, response) => {
        const url = new URL(request.url, origin);
        const path = url.pathname;
        const html = (value) => { response.setHeader('Content-Type', 'text/html'); response.end(value.replaceAll(/http:\/\/localhost(?::8000)?/g, origin)); };
        if (path === '/pasien') { if (url.search) searches++; html(fixtures.index); return; }
        if (path.startsWith('/pasien/identitas/') && path.endsWith('/foto')) { response.setHeader('Content-Type', 'image/webp'); response.end(await readFile(join(output, 'photo.webp'))); return; }
        if (path.startsWith('/pasien/identitas/')) { html(path.endsWith('/edit') ? fixtures.edit : fixtures.detail); return; }
        if (/^\/(build|template)\//.test(path) && !path.includes('..')) {
            try {
                const data = await readFile(resolve('public', `.${path}`));
                response.setHeader('Content-Type', ({ js: 'text/javascript', css: 'text/css', png: 'image/png', woff: 'font/woff', woff2: 'font/woff2' })[path.split('.').at(-1)] ?? 'application/octet-stream');
                response.end(data); return;
            } catch { /* Asset failures are reported by the browser assertions. */ }
        }
        response.writeHead(404); response.end();
    });
    server.listen(0, '127.0.0.1'); await once(server, 'listening');
    origin = `http://127.0.0.1:${server.address().port}`;
    const chrome = spawn(browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${join(output, 'profile')}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
    let socket;
    t.after(() => { socket?.close(); chrome.kill(); server.closeAllConnections(); server.close(); });
    const endpoint = await new Promise((resolve, reject) => {
        let stderr = '';
        const timer = setTimeout(() => reject(new Error(`Browser startup timed out: ${stderr.slice(-1000)}`)), 15000);
        chrome.once('error', reject);
        chrome.stderr.on('data', (chunk) => { stderr += chunk; const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
    });
    socket = new WebSocket(endpoint); await once(socket, 'open');
    let next = 0;
    const pending = new Map(), errors = [];
    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const task = pending.get(message.id); pending.delete(message.id);
            message.error ? task.reject(new Error(JSON.stringify(message.error))) : task.resolve(message.result);
        } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
    });
    const command = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
        const id = ++next;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 15000);
        pending.set(id, { resolve: (value) => { clearTimeout(timer); resolve(value); }, reject: (error) => { clearTimeout(timer); reject(error); } });
        socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
    const { targetId } = await command('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await command('Target.attachToTarget', { targetId, flatten: true });
    const page = (method, params) => command(method, params, sessionId);
    await page('Page.enable'); await page('Runtime.enable');
    const evaluate = async (expression) => {
        const result = await page('Runtime.evaluate', { expression: `(() => {${expression}})()`, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
        return result.result.value;
    };
    const wait = async (condition) => assert.equal(await evaluate(`return new Promise(resolve => {const start=performance.now(); const check=()=>{if(${condition})resolve(true);else if(performance.now()-start>8000)resolve(false);else requestAnimationFrame(check)};check()})`), true, condition);
    const screenshot = async (name) => { const { data } = await page('Page.captureScreenshot', { format: 'png' }); await writeFile(join(output, `${name}.png`), Buffer.from(data, 'base64')); };
    await page('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await page('Page.navigate', { url: `${origin}/pasien` });
    await wait("document.readyState === 'complete' && document.querySelector('[data-patient-row] img')?.complete && document.querySelector('[data-patient-row] img').naturalWidth > 0");
    await evaluate('window.confirm = () => true;');
    await screenshot('patients-desktop');
    assert.equal(await evaluate('return document.documentElement.scrollWidth <= innerWidth'), true);
    assert.equal(await evaluate("const row=document.querySelector('[data-patient-row]'); return row.querySelector('img').getBoundingClientRect().left < row.querySelector('strong').getBoundingClientRect().left"), true);
    await evaluate("document.querySelector('[data-patient-open=detail]').click()");
    await wait("document.querySelector('[data-patient-modal-body]').textContent.includes('Riwayat pekerjaan')");
    await wait("getComputedStyle(document.querySelector('[data-patient-modal]')).opacity === '1'");
    await screenshot('patient-detail');
    assert.equal(await evaluate("return getComputedStyle(document.querySelector('[data-patient-modal]')).transitionDuration.includes('0.2s')"), true);
    await evaluate("document.querySelector('[data-patient-modal-close]').click()");
    await wait("!document.querySelector('[data-patient-modal]').open");
    await evaluate("document.querySelector('[data-patient-open=edit]').click()");
    await wait("document.querySelector('[data-patient-edit-form]') !== null");
    await evaluate("return (async()=>{const input=document.querySelector('[data-patient-photo-input]');const bytes=await (await fetch(document.querySelector('[data-patient-photo-preview]').src)).blob();const files=new DataTransfer(); files.items.add(new File([bytes], 'preview.jpg', {type:'image/jpeg'}));input.files=files.files;input.dispatchEvent(new Event('change',{bubbles:true}))})()");
    assert.equal(await evaluate("return document.querySelector('[data-patient-photo-preview]').src.startsWith('blob:')"), true);
    await screenshot('patient-edit');
    await evaluate("document.querySelector('[data-patient-photo-reset]').click(); document.querySelector('[data-patient-modal-close]').click()");
    await wait("!document.querySelector('[data-patient-modal]').open");
    await page('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await wait("getComputedStyle(document.querySelector('[data-patient-modal]')).opacity === '0' && document.getElementById('sidebar').getBoundingClientRect().right <= 1");
    await screenshot('patients-mobile');
    assert.equal(await evaluate('return document.documentElement.scrollWidth <= innerWidth'), true);
    await evaluate("document.querySelector('[data-patient-row]').scrollIntoView({block:'center'})");
    assert.equal(await evaluate("return [...document.querySelectorAll('[data-patient-filter] select')].every(select => select.getBoundingClientRect().width <= select.parentElement.clientWidth)"), true);
    await screenshot('patients-mobile-row');
    await evaluate("const q=document.querySelector('[data-patient-filter] [name=q]'); q.value='Ichwan';q.dispatchEvent(new Event('input',{bubbles:true}))");
    await wait("location.search.includes('q=Ichwan') && !document.querySelector('[data-patient-workspace]').hasAttribute('aria-busy')");
    assert.ok(searches >= 1);
    await evaluate("document.querySelector('[data-patient-open=detail]').click()");
    await wait("document.querySelector('[data-patient-modal-body]').textContent.includes('Profil pasien')");
    await wait("getComputedStyle(document.querySelector('[data-patient-modal]')).opacity === '1'");
    assert.equal(await evaluate("const modal=document.querySelector('[data-patient-modal]');return modal.scrollWidth <= modal.clientWidth"), true);
    await screenshot('patient-detail-mobile');
    await page('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    assert.equal(await evaluate("return getComputedStyle(document.querySelector('[data-patient-modal]')).transitionDuration.includes('0.125s')"), true);
    assert.equal(await evaluate("return getComputedStyle(document.querySelector('.skeleton-block')).animationName"), 'none');
    assert.deepEqual(errors, []);
    t.diagnostic(`Screenshots: ${output}`);
});
