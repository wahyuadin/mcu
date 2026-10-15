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
    let origin;
    let dataRequests = 0;
    let writes = 0;
    const server = createServer(async (request, response) => {
      const path = new URL(request.url, 'http://localhost').pathname;
      if (path === '/') {
        response.setHeader('Content-Type', 'text/html');
        response.end(html.replaceAll(/https?:\/\/localhost(?::8000)?/g, origin));
        return;
      }
      if (path === '/hrd/data') {
        dataRequests++;
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ ...data, updated_at: new Date().toISOString() }));
        return;
      }
      if (path === '/hrd/preview') {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const widgets = JSON.parse(Buffer.concat(chunks).toString()).widgets;
        response.setHeader('Content-Type', 'application/json');
        response.end(
          JSON.stringify({
            ...data,
            widgets: widgets.map((widget) => ({
              ...(data.widgets.find((row) => row.source === widget.source) ?? {
                rows: [],
                total: 0,
              }),
              ...widget,
            })),
          })
        );
        return;
      }
      if (path === '/hrd/layout') {
        writes++;
        response.setHeader('Content-Type', 'application/json');
        response.end('{"message":"Saved"}');
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
      await page('Page.enable');
      await page('Runtime.enable');
      const evaluate = async (expression) => {
        const response = await page('Runtime.evaluate', {
          expression,
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
      await waitFor("document.querySelector('[data-hrd-stat=planned]')?.textContent === '529'");
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
      await screenshot('dashboard-desktop.png');
      const requestsBefore = dataRequests;
      await evaluate(
        "window.__hrdRow = document.querySelector('[data-hrd-participants] tr'); const box = window.__hrdRow.querySelector('input'); box.checked = true; box.dispatchEvent(new Event('change')); box.focus(); window.scrollTo(0, 400); window.__hrdScroll = scrollY"
      );
      await waitFor(
        `document.querySelector('[data-hrd-updated]').textContent !== '' && ${requestsBefore + 1} <= performance.getEntriesByType('resource').filter(r => r.name.includes('/hrd/data')).length`
      );
      assert.equal(
        await evaluate(
          "window.__hrdRow === document.querySelector('[data-hrd-participants] tr') && window.__hrdRow.querySelector('input').checked && scrollY === window.__hrdScroll"
        ),
        true
      );
      await evaluate("document.querySelector('[data-hrd-builder-open]').click()");
      await waitFor("document.querySelector('[data-hrd-builder-list]').children.length > 0");
      assert.equal(
        await evaluate(
          "const d=document.querySelector('[data-hrd-builder]'); d.scrollWidth <= d.clientWidth && d.getBoundingClientRect().height <= innerHeight * .91"
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
          "document.querySelector('[data-hrd-builder-list]').firstElementChild.dataset.width"
        ),
        '2'
      );
      await screenshot('builder-desktop.png');
      await evaluate(
        "document.querySelector('[data-hrd-builder-close]').click(); window.scrollTo(0,0)"
      );
      await page('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
      });
      await page('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 1 });
      assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
      assert.equal(
        await evaluate(
          "getComputedStyle(document.querySelector('[data-hrd-filters] input[type=search]')).fontSize"
        ),
        '16px'
      );
      await screenshot('dashboard-mobile.png');
      await evaluate("document.querySelector('[data-hrd-builder-open]').click()");
      await waitFor("document.querySelector('[data-hrd-builder]').open");
      assert.equal(
        await evaluate(
          "const d=document.querySelector('[data-hrd-builder]'); const r=d.getBoundingClientRect(); d.scrollWidth <= d.clientWidth && r.left >= 8 && r.right <= innerWidth-8 && r.height <= innerHeight*.91"
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
      assert.deepEqual(errors, []);
      assert.equal(writes, 0);
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
