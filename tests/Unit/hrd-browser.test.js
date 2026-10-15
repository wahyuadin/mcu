import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { join, resolve } from 'node:path';

const fixtures = process.env.MCU_PRESENTATION_OUTPUT;
const chromePath = process.env.MCU_BROWSER_EXECUTABLE;

test(
  'Chrome renders desktop/mobile dashboard and builder without overflow or runtime errors',
  { skip: !fixtures || !chromePath, timeout: 60000 },
  async (t) => {
    const html = await readFile(join(fixtures, 'dashboard.html'), 'utf8');
    const data = JSON.parse(await readFile(join(fixtures, 'data.json'), 'utf8'));
    const workspace = JSON.parse(await readFile(join(fixtures, 'workspace.json'), 'utf8'));
    const readonlyHtml = await readFile(join(fixtures, 'readonly-examination.html'), 'utf8');
    const csv = await readFile(join(fixtures, 'Rekap_MCU.csv'));
    const participantPhoto = await readFile(join(fixtures, 'participant-photo.webp'));
    let csvRequests = 0;
    const dashboards = [];
    let defaultDashboard = workspace.default_dashboard ?? {
      id: 'default',
      name: 'Dashboard',
      version: 1,
    };
    const scopeRequests = [];
    let active = 'default';
    let origin;
    let dataRequests = 0;
    let writes = 0;
    const server = createServer(async (request, response) => {
      const path = new URL(request.url, 'http://localhost').pathname;
      if (path === '/' || path === '/dashboard' || path.endsWith('/pemeriksaan')) {
        response.setHeader('Content-Type', 'text/html');
        response.end(
          (path.endsWith('/pemeriksaan') ? readonlyHtml : html).replaceAll(
            /https?:\/\/localhost(?::8000)?/g,
            origin
          )
        );
        return;
      }
      if (path.endsWith('/lampiran/foto')) {
        response.writeHead(200, { 'Content-Type': 'image/webp' });
        response.end(participantPhoto);
        return;
      }
      if (path === '/dashboard/data') {
        dataRequests++;
        const query = new URL(request.url, 'http://localhost').searchParams;
        scopeRequests.push(query.toString());
        const matching =
          !query.get('visit_status') ||
          query.get('visit_status') === workspace.visit_options[0].status;
        const selected =
          new URL(request.url, 'http://localhost').searchParams.get('dashboard') ?? active;
        const dashboard = dashboards.find((entry) => entry.id === selected);
        response.setHeader('Content-Type', 'application/json');
        response.end(
          JSON.stringify({
            ...workspace,
            default_dashboard: defaultDashboard,
            stats: matching
              ? workspace.stats
              : Object.fromEntries(Object.keys(workspace.stats).map((key) => [key, 0])),
            recent_visits: matching ? workspace.recent_visits : [],
            active_dashboard: selected,
            dashboard: dashboard ?? null,
            dashboards,
            personal_layout: Boolean(dashboard),
            layout: dashboard?.widgets ?? workspace.layout,
            widgets:
              dashboard?.widgets.map((widget) => ({
                ...(workspace.widgets.find((entry) => entry.source === widget.source) ?? {
                  kind: 'choice',
                  rows: [],
                  total: 0,
                }),
                ...widget,
              })) ?? workspace.widgets,
            updated_at: new Date().toISOString(),
          })
        );
        return;
      }
      if (path === '/hrd/data') {
        dataRequests++;
        response.setHeader('Content-Type', 'application/json');
        response.end(
          JSON.stringify({ ...data, updated_at: new Date().toISOString() }).replaceAll(
            /https?:\/\/localhost(?::8000)?/g,
            origin
          )
        );
        return;
      }
      if (path === '/hrd/rekap') {
        csvRequests++;
        if (csvRequests === 1) {
          response.writeHead(429, { 'Content-Type': 'application/json', 'Retry-After': '1' });
          response.end('{"message":"Too Many Attempts."}');
        } else {
          response.writeHead(200, {
            'Content-Type': 'text/csv; charset=UTF-8',
            'Content-Disposition': 'attachment; filename=Rekap_MCU.csv',
          });
          response.end(csv);
        }
        return;
      }
      if (path === '/dashboard/preview') {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const widgets = JSON.parse(Buffer.concat(chunks).toString()).widgets;
        response.setHeader('Content-Type', 'application/json');
        response.end(
          JSON.stringify({
            ...data,
            widgets: widgets.map((widget) => ({
              ...([...workspace.widgets, ...data.widgets].find(
                (row) => row.source === widget.source
              ) ?? {
                rows: [],
                total: 0,
              }),
              ...widget,
            })),
          })
        );
        return;
      }
      if (path.startsWith('/dashboard/saved')) {
        writes++;
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const body = JSON.parse(Buffer.concat(chunks).toString());
        let dashboard;
        if (request.method === 'POST') {
          dashboard = { ...body, id: `dashboard-${dashboards.length + 1}`, version: 1 };
          dashboards.push(dashboard);
        } else {
          dashboard = path.endsWith('/default')
            ? defaultDashboard
            : dashboards.find((entry) => entry.id === path.split('/').at(-1));
          Object.assign(dashboard, body, { version: dashboard.version + 1 });
        }
        if (dashboard.id !== 'default') active = dashboard.id;
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ dashboard }));
        return;
      }
      if (path === '/dashboard/selection') {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        active = JSON.parse(Buffer.concat(chunks).toString()).dashboard;
        response.setHeader('Content-Type', 'application/json');
        response.end('{"message":"Selected"}');
        return;
      }
      if (path === '/favicon.ico') {
        response.writeHead(204);
        response.end();
        return;
      }
      if (/^\/(build|template|Template|css)\//.test(path) && !path.includes('..')) {
        try {
          const file = await readFile(resolve('public', path.slice(1)));
          const types = {
            js: 'text/javascript',
            css: 'text/css',
            png: 'image/png',
            woff2: 'font/woff2',
            jpg: 'image/jpeg',
          };
          response.setHeader(
            'Content-Type',
            types[path.split('.').at(-1)] ?? 'application/octet-stream'
          );
          response.end(file);
          return;
        } catch {
          /* Missing assets fail the browser assertions. */
        }
      }
      response.writeHead(404);
      response.end();
    });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    origin = `http://127.0.0.1:${server.address().port}`;
    const profile = await mkdtemp(join(fixtures, 'chrome-test-'));
    const chrome = spawn(
      chromePath,
      [
        '--headless=new',
        '--disable-gpu',
        '--enable-logging=stderr',
        '--no-first-run',
        '--no-default-browser-check',
        '--remote-debugging-port=0',
        `--user-data-dir=${profile}`,
        'about:blank',
      ],
      { stdio: ['ignore', 'ignore', 'pipe'] }
    );
    let socket;
    t.after(() => {
      socket?.close();
      chrome.kill('SIGTERM');
      server.closeAllConnections();
      server.close();
    });
    try {
      const endpoint = await new Promise((resolve, reject) => {
        let output = '';
        const timer = setTimeout(
          () => reject(new Error(`Chrome startup timed out: ${output.slice(-2000)}`)),
          15000
        );
        chrome.once('error', reject);
        chrome.once('exit', (code, signal) => {
          clearTimeout(timer);
          reject(new Error(`Chrome exited ${code} (${signal}): ${output.slice(-2000)}`));
        });
        chrome.stderr.on('data', (chunk) => {
          output += chunk;
          const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
          if (match) {
            clearTimeout(timer);
            resolve(match[1]);
          }
        });
      });
      socket = new WebSocket(endpoint);
      await once(socket, 'open');
      let next = 0;
      const pending = new Map();
      const errors = [];
      socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id) {
          const request = pending.get(message.id);
          pending.delete(message.id);
          message.error
            ? request.reject(new Error(JSON.stringify(message.error)))
            : request.resolve(message.result);
        } else if (message.method === 'Runtime.exceptionThrown')
          errors.push(
            message.params.exceptionDetails.exception?.description ??
              message.params.exceptionDetails.text
          );
      });
      const command = (method, params = {}, sessionId) =>
        new Promise((resolve, reject) => {
          const id = ++next;
          const timer = setTimeout(() => {
            pending.delete(id);
            reject(new Error(`Chrome command timed out: ${method}`));
          }, 15000);
          pending.set(id, {
            resolve: (value) => {
              clearTimeout(timer);
              resolve(value);
            },
            reject: (error) => {
              clearTimeout(timer);
              reject(error);
            },
          });
          socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        });
      const { targetId } = await command('Target.createTarget', { url: 'about:blank' });
      const { sessionId } = await command('Target.attachToTarget', { targetId, flatten: true });
      const page = (method, params) => command(method, params, sessionId);
      await command('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: profile });
      await page('Page.enable');
      await page('Runtime.enable');
      const evaluate = async (expression) => {
        const response = await page('Runtime.evaluate', {
          expression: `(() => eval(${JSON.stringify(expression)}))()`,
          returnByValue: true,
          awaitPromise: true,
        });
        if (response.exceptionDetails)
          throw new Error(
            response.exceptionDetails.exception?.description ?? response.exceptionDetails.text
          );
        return response.result.value;
      };
      const waitFor = async (condition) => {
        const result = await evaluate(
          `new Promise(resolve => { const start = performance.now(); const check = () => { if (${condition}) resolve(true); else if (performance.now() - start > 10000) resolve(false); else requestAnimationFrame(check); }; check(); })`
        );
        assert.equal(result, true, condition);
      };
      const screenshot = async (name) => {
        const { data } = await page('Page.captureScreenshot', {
          format: 'png',
          captureBeyondViewport: false,
        });
        await writeFile(join(fixtures, name), Buffer.from(data, 'base64'));
      };
      await page('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
      });
      await page('Page.navigate', { url: origin });
      await waitFor(
        "document.querySelector('[data-dashboard-stat=peserta]')?.textContent === '1' && !document.querySelector('[data-hrd-builder-open]').disabled"
      );
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
      await screenshot('dashboard-desktop.png');
      await evaluate("document.querySelector('[data-dashboard-rename]').click()");
      await waitFor("document.querySelector('[data-dashboard-name-dialog]').open");
      await evaluate(
        "const form=document.querySelector('[data-dashboard-name-form]');form.elements.name.value='Dashboard saya';form.requestSubmit()"
      );
      await waitFor(
        "document.querySelector('[data-dashboard-select]').selectedOptions[0].textContent==='Dashboard saya'"
      );
      const differentStatus = workspace.visit_options[0].status === 'draft' ? 'closed' : 'draft';
      await evaluate(
        `const select=document.querySelector('[data-dashboard-visit-status]');select.value=${JSON.stringify(differentStatus)};select.dispatchEvent(new Event('change'))`
      );
      await waitFor("document.querySelector('[data-dashboard-stat=peserta]').textContent==='0'");
      assert.ok(scopeRequests.some((query) => query.includes(`visit_status=${differentStatus}`)));
      await evaluate(
        "document.querySelector('[data-dashboard-filter-reset]').click();const select=document.querySelector('[data-dashboard-context]');select.value=" +
          JSON.stringify(workspace.company.id) +
          ";select.dispatchEvent(new Event('change'))"
      );
      await waitFor("document.querySelector('[data-dashboard-stat=peserta]').textContent==='1'");
      const requestsBefore = dataRequests;
      await evaluate(
        "window.__hrdRow = document.querySelector('[data-dashboard-visits] tr'); window.__hrdRow.querySelector('a').focus(); window.scrollTo(0, 200); window.__hrdScroll = scrollY"
      );
      await waitFor(
        `${requestsBefore + 1} <= performance.getEntriesByType('resource').filter(r => r.name.includes('/dashboard/data')).length`
      );
      assert.equal(
        await evaluate(
          "window.__hrdRow === document.querySelector('[data-dashboard-visits] tr') && scrollY === window.__hrdScroll"
        ),
        true
      );
      await evaluate("document.querySelector('[data-dashboard-reports]').open=true");
      await waitFor("document.querySelector('[data-hrd-participants] tr input')");
      assert.equal(
        await evaluate(
          "document.querySelector('[data-hrd-participants] tr a').href.includes('/pemeriksaan')"
        ),
        true
      );
      await evaluate(
        "const create=URL.createObjectURL;URL.createObjectURL=blob=>{window.__csvBlob=blob;return create(blob)};document.querySelector('[data-hrd-recap]').click()"
      );
      await waitFor(
        "document.querySelector('[data-hrd-recap]').disabled && document.querySelector('[data-hrd-recap]').textContent.includes('detik')"
      );
      await waitFor("!document.querySelector('[data-hrd-recap]').disabled");
      assert.equal(csvRequests, 1);
      await evaluate("document.querySelector('[data-hrd-recap]').click()");
      await waitFor("window.__csvBlob && !document.querySelector('[data-hrd-recap]').disabled");
      assert.equal(
        await evaluate("window.__csvBlob.text().then(text=>text.startsWith('sep=;\\r\\n'))"),
        true
      );
      assert.equal(csvRequests, 2);
      await evaluate(
        "const box=document.querySelector('[data-hrd-participants] tr input');box.checked=true;box.dispatchEvent(new Event('change'))"
      );
      assert.match(
        await evaluate("document.querySelector('[data-hrd-selected]').textContent"),
        /1 peserta/
      );
      await evaluate("document.querySelector('[data-dashboard-reports]').open=false");
      await evaluate("document.querySelector('[data-hrd-builder-open]').click()");
      await waitFor("document.querySelector('[data-dashboard-widgets] .hrd-builder-card')");
      assert.equal(
        await evaluate(
          "[...document.querySelectorAll('[data-dashboard-widgets] button')].some(node=>['⠿ Seret','↑','↓','↔ Ukuran'].includes(node.textContent))"
        ),
        false
      );
      const dragPoints = await evaluate(
        "const cards=document.querySelectorAll('[data-hrd-edit-index]');window.__draggedCard=cards[0];const a=cards[0].getBoundingClientRect(),b=cards[1].getBoundingClientRect();({from:{x:a.left+30,y:a.top+50},to:{x:b.left+60,y:b.top+50}})"
      );
      await page('Input.dispatchMouseEvent', {
        type: 'mousePressed',
        button: 'left',
        clickCount: 1,
        ...dragPoints.from,
      });
      await page('Input.dispatchMouseEvent', {
        type: 'mouseMoved',
        button: 'left',
        buttons: 1,
        ...dragPoints.to,
      });
      await waitFor(
        "document.querySelector('[data-hrd-edit-index]')!==window.__draggedCard && document.querySelector('.hrd-drag-ghost')"
      );
      assert.equal(
        await evaluate(
          "document.querySelector('.hrd-drag-ghost').style.transform.includes('translate')"
        ),
        true
      );
      await screenshot('drag-desktop.png');
      await page('Input.dispatchMouseEvent', {
        type: 'mouseReleased',
        button: 'left',
        clickCount: 1,
        ...dragPoints.to,
      });
      await waitFor("!document.querySelector('.hrd-drag-ghost')");
      await evaluate("document.querySelector('[data-dashboard-undo]').click()");
      await waitFor(
        "document.querySelector('[data-hrd-edit-index] .hrd-widget-title').textContent==='Perusahaan'"
      );
      await evaluate(
        "document.querySelector('[data-dashboard-redo]').click();document.querySelector('[data-dashboard-undo]').click()"
      );
      await evaluate(
        'Promise.all(document.getAnimations().map(animation=>animation.finished.catch(()=>{})))'
      );
      await evaluate(
        "document.querySelector('[data-hrd-settings-open]').click(); const field=document.querySelector('[data-hrd-widget-form] input[name=title]'); field.value='Live edit'; field.dispatchEvent(new Event('input',{bubbles:true}))"
      );
      assert.equal(
        await evaluate(
          "document.querySelector('[data-dashboard-widgets] .hrd-widget-title').textContent"
        ),
        'Live edit'
      );
      assert.equal(
        await evaluate(
          "const d=document.querySelector('[data-dashboard-editor]'); d.scrollWidth <= d.clientWidth"
        ),
        true
      );
      const bounds = await evaluate(
        "const b=document.querySelector('.hrd-resize-handle'); b.scrollIntoView({block:'center'}); const r=b.getBoundingClientRect(); ({x:r.x+r.width/2,y:r.y+r.height/2})"
      );
      await page('Input.dispatchMouseEvent', {
        type: 'mousePressed',
        button: 'left',
        clickCount: 1,
        ...bounds,
      });
      await page('Input.dispatchMouseEvent', {
        type: 'mouseMoved',
        button: 'left',
        buttons: 1,
        x: bounds.x + 350,
        y: bounds.y,
      });
      await page('Input.dispatchMouseEvent', {
        type: 'mouseReleased',
        button: 'left',
        clickCount: 1,
        x: bounds.x + 350,
        y: bounds.y,
      });
      assert.equal(
        await evaluate(
          "document.querySelector('[data-dashboard-widgets]').firstElementChild.dataset.width"
        ),
        '2'
      );
      assert.equal(
        await evaluate("document.querySelector('[data-hrd-widget-form] select[name=width]').value"),
        '2'
      );
      await screenshot('builder-desktop.png');
      await evaluate("document.querySelector('[data-dashboard-save-as]').click()");
      await waitFor("document.querySelector('[data-dashboard-name-dialog]').open");
      await evaluate(
        "const form=document.querySelector('[data-dashboard-name-form]');form.elements.name.value='Progres tersimpan';form.requestSubmit()"
      );
      await waitFor(
        "document.querySelector('[data-hrd-builder]').hidden && document.querySelector('[data-dashboard-select]').selectedOptions[0].textContent === 'Progres tersimpan'"
      );
      assert.equal(dashboards.length, 1);
      assert.equal(writes, 2);
      await evaluate(
        "const select=document.querySelector('[data-dashboard-context]');select.value='';select.dispatchEvent(new Event('change'))"
      );
      await waitFor(
        "document.querySelector('[data-dashboard-reports]').hidden && !document.querySelector('[data-dashboard-select]').disabled"
      );
      assert.equal(
        await evaluate("document.querySelector('[data-dashboard-select]').value"),
        'dashboard-1'
      );
      await evaluate(
        `const select=document.querySelector('[data-dashboard-context]');select.value=${JSON.stringify(workspace.company.id)};select.dispatchEvent(new Event('change'))`
      );
      await evaluate('window.scrollTo(0,0)');
      await page('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
      });
      await page('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 1 });
      await waitFor("document.querySelector('#sidebar').getBoundingClientRect().right <= 0");
      await evaluate(
        "const select=document.querySelector('[data-dashboard-select]');select.value='default';select.dispatchEvent(new Event('change'))"
      );
      await waitFor("!document.querySelector('[data-dashboard-default]').hidden");
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
      assert.equal(
        await evaluate(
          "getComputedStyle(document.querySelector('[data-dashboard-select]')).fontSize"
        ),
        '16px'
      );
      await screenshot('dashboard-mobile.png');
      await evaluate(
        "const select=document.querySelector('[data-dashboard-select]');select.value='dashboard-1';select.dispatchEvent(new Event('change'))"
      );
      await waitFor(
        "document.querySelector('[data-dashboard-default]').hidden && !document.querySelector('[data-dashboard-select]').disabled"
      );
      await evaluate("document.querySelector('[data-hrd-builder-open]').click()");
      await waitFor("!document.querySelector('[data-hrd-builder]').hidden");
      assert.equal(
        await evaluate(
          "const d=document.querySelector('[data-dashboard-widgets]'); d.scrollWidth <= d.clientWidth && document.documentElement.scrollWidth <= innerWidth"
        ),
        true
      );
      assert.equal(
        await evaluate(
          "document.querySelector('[data-hrd-builder-close]').getBoundingClientRect().height >= 44"
        ),
        true
      );
      await screenshot('builder-mobile.png');
      const touchDrag = await evaluate(
        "const cards=document.querySelectorAll('[data-hrd-edit-index]');window.__touchCard=cards[0];cards[0].scrollIntoView({block:'start'});window.scrollBy(0,-120);window.__touchScroll=scrollY;const a=cards[0].querySelector('.hrd-widget-heading').getBoundingClientRect(),b=cards[1].querySelector('.hrd-widget-heading').getBoundingClientRect();({from:{x:a.left+24,y:a.top+28},to:{x:b.left+24,y:b.top+28}})"
      );
      await page('Input.dispatchTouchEvent', {
        type: 'touchStart',
        touchPoints: [{ ...touchDrag.from, id: 1 }],
      });
      assert.equal(
        await evaluate('scrollY===window.__touchScroll'),
        true,
        'Touch start must not move the page'
      );
      await page('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [{ ...touchDrag.to, id: 1 }],
      });
      await waitFor(
        "document.querySelector('[data-hrd-edit-index]')!==window.__touchCard && document.querySelector('.hrd-drag-ghost')"
      );
      await screenshot('drag-mobile.png');
      assert.equal(
        await evaluate('scrollY===window.__touchScroll'),
        true,
        JSON.stringify(
          await evaluate(
            '({before:window.__touchScroll,after:scrollY,height:innerHeight,card:window.__touchCard.getBoundingClientRect().toJSON()})'
          )
        )
      );
      await page('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await waitFor("!document.querySelector('.hrd-drag-ghost')");
      await evaluate(
        "document.querySelector('[data-dashboard-undo]').click();Promise.all(document.getAnimations().map(animation=>animation.finished.catch(()=>{})))"
      );
      const touchResize = await evaluate(
        "const card=document.querySelector('[data-hrd-edit-index]');card.scrollIntoView({block:'start'});window.scrollBy(0,-120);window.__touchHeight=card.getBoundingClientRect().height;const r=card.querySelector('[data-dashboard-resize=s]').getBoundingClientRect();({x:r.left+r.width/2,y:r.top+r.height/2})"
      );
      await page('Input.dispatchTouchEvent', {
        type: 'touchStart',
        touchPoints: [{ ...touchResize, id: 1 }],
      });
      await page('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [{ x: touchResize.x, y: touchResize.y + 200, id: 1 }],
      });
      await waitFor("document.querySelector('[data-hrd-edit-index]').dataset.height==='2'");
      await page('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      assert.equal(
        await evaluate(
          "document.querySelector('[data-hrd-edit-index]').getBoundingClientRect().height>window.__touchHeight+150"
        ),
        true
      );
      await screenshot('resize-mobile.png');
      await evaluate("document.querySelector('[data-dashboard-undo]').click()");
      await evaluate(
        "document.querySelector('[data-hrd-settings-open]').click();const field=document.querySelector('[data-hrd-widget-form] input[name=title]');field.value='Mobile live edit';field.dispatchEvent(new Event('input',{bubbles:true}))"
      );
      assert.equal(
        await evaluate(
          "document.querySelector('[data-dashboard-widgets] .hrd-widget-title').textContent"
        ),
        'Mobile live edit'
      );
      assert.equal(
        await evaluate(
          "const panel=document.querySelector('[data-hrd-widget-settings]');const bounds=panel.getBoundingClientRect();bounds.left>=0 && bounds.right<=innerWidth && bounds.height<=innerHeight*.61"
        ),
        true
      );
      await screenshot('settings-mobile.png');
      assert.deepEqual(errors, []);
      assert.equal(writes, 2);
      await evaluate(
        "document.querySelector('[data-hrd-builder-close]').click();document.querySelector('[data-hrd-builder-confirm-accept]').click()"
      );
      await waitFor("document.querySelector('[data-hrd-builder]').hidden");
      await evaluate("document.querySelector('[data-dashboard-reports]').open=true");
      await waitFor("document.querySelector('[data-hrd-participants] tr a')");
      await evaluate("document.querySelector('[data-hrd-participants] tr a').click()");
      await waitFor(
        "location.pathname.endsWith('/pemeriksaan') && document.body.textContent.includes('Hanya baca')"
      );
      assert.equal(
        await evaluate(
          "!document.querySelector('[data-exam-form]') && [...document.querySelectorAll('fieldset input, fieldset select, fieldset textarea')].every(input=>input.matches(':disabled'))"
        ),
        true
      );
      await waitFor(
        "[...document.querySelectorAll('img')].filter(image=>image.getClientRects().length).every(image=>image.complete && image.naturalWidth>0)"
      );
      await screenshot('readonly-mobile.png');
      assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'), true);
      assert.equal(
        await evaluate(
          "[...document.querySelectorAll('img')].filter(image=>image.getClientRects().length).every(image=>image.complete && image.naturalWidth>0)"
        ),
        true
      );
      await page('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
      });
      await page('Emulation.setTouchEmulationEnabled', { enabled: false });
      await screenshot('readonly-desktop.png');
      await evaluate('document.querySelector(\'a[href*="#hrd-participants"]\').click()');
      await waitFor(
        "location.pathname==='/dashboard' && document.querySelector('[data-dashboard-reports]')?.open && document.querySelector('[data-hrd-participants] tr a')"
      );
      assert.deepEqual(errors, []);
    } finally {
      socket?.close();
      if (chrome.exitCode === null && chrome.signalCode === null) {
        chrome.kill('SIGTERM');
        await once(chrome, 'exit');
      }
      server.closeAllConnections();
      server.close();
      await rm(profile, { recursive: true, force: true });
    }
  }
);
