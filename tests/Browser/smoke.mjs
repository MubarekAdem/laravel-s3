import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, writeFile, rm, access} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {createServer} from 'node:net';

const root = resolve(import.meta.dirname, '../..');
const temporary = await mkdtemp(join(tmpdir(), 'harbor-browser-'));
const screenshots = join(root, 'storage/framework/browser-smoke');
const env = {...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', DB_CONNECTION: 'sqlite', DB_DATABASE: join(temporary, 'database.sqlite'), DB_URL: '', SESSION_DRIVER: 'cookie', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', OBJECT_STORE_PATH: join(temporary, 'objects')};
let server, browser, socket;
const pending = new Map();
let id = 0;
const exceptions = [];
async function port() {
    const listener = createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const value = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    return value;
}
async function until(check, label) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        try { const result = await check(); if (result) return result; } catch {}
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Timed out: ' + label);
}
function php(args) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {cwd: root, env, encoding: 'utf8', windowsHide: true});
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
}
function call(method, params = {}, sessionId) {
    return new Promise((resolve, reject) => {
        const current = ++id;
        const timer = setTimeout(() => { pending.delete(current); reject(new Error('CDP timeout: ' + method)); }, 15000);
        pending.set(current, {resolve: value => { clearTimeout(timer); resolve(value); }, reject});
        socket.send(JSON.stringify({id: current, method, params, ...(sessionId ? {sessionId} : {})}));
    });
}
try {
    await writeFile(env.DB_DATABASE, '');
    await mkdir(env.OBJECT_STORE_PATH);
    await mkdir(screenshots, {recursive: true});
    php(['artisan', 'migrate', '--force', '--no-interaction']);
    php(['tests/Browser/bootstrap.php']);
    const httpPort = await port();
    const base = `http://127.0.0.1:${httpPort}`;
    server = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${httpPort}`, '-t', 'public', 'tests/Browser/router.php'], {cwd: root, env: {...env, APP_URL: base}, windowsHide: true, stdio: 'ignore'});
    await until(async () => (await fetch(base + '/login')).ok, 'local server');
    const candidates = [process.env.BROWSER_BINARY, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].filter(Boolean);
    let executable;
    for (const candidate of candidates) { try { await access(candidate); executable = candidate; break; } catch {} }
    if (!executable) throw new Error('Set BROWSER_BINARY to an installed Chromium browser.');
    const debugPort = await port();
    browser = spawn(executable, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=' + debugPort, '--user-data-dir=' + join(temporary, 'profile'), 'about:blank'], {windowsHide: true, stdio: 'ignore'});
    const version = await until(async () => (await fetch(`http://127.0.0.1:${debugPort}/json/version`)).json(), 'browser');
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    socket.onmessage = ({data}) => {
        const message = JSON.parse(data);
        if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
        const waiter = pending.get(message.id);
        if (waiter) { pending.delete(message.id); message.error ? waiter.reject(new Error(JSON.stringify(message.error))) : waiter.resolve(message.result); }
    };
    const {targetId} = await call('Target.createTarget', {url: 'about:blank'});
    const {sessionId} = await call('Target.attachToTarget', {targetId, flatten: true});
    const page = (method, params) => call(method, params, sessionId);
    const evaluate = async expression => (await page('Runtime.evaluate', {expression, returnByValue: true})).result.value;
    const capture = async params => {
        await until(() => evaluate('document.readyState === "complete" && document.documentElement.clientWidth > 0'), 'page layout');
        await page('Runtime.evaluate', {expression: 'new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))', awaitPromise: true});
        return page('Page.captureScreenshot', params);
    };
    await page('Page.enable');
    await page('Runtime.enable');
    await page('Emulation.setEmulatedMedia', {features: [{name: 'prefers-reduced-motion', value: 'reduce'}]});
    for (const [path, name, selector] of [['/', 'landing', '#hero-title'], ['/api/docs', 'api-docs', '.docs-main h1']]) {
        for (const width of [1280, 390]) {
            await page('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
            await page('Page.navigate', {url: base + path});
            await until(() => evaluate(`!!document.querySelector(${JSON.stringify(selector)})`), name);
            if (await evaluate('document.documentElement.scrollWidth > innerWidth')) throw new Error(name + ' overflows at ' + width);
            const screenshot = await capture({format: 'png'});
            await writeFile(join(screenshots, `${name}-${width}.png`), Buffer.from(screenshot.data, 'base64'));
        }
    }
    for (const width of [1280, 390]) {
        await page('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
        await page('Page.navigate', {url: base + '/login'});
        await until(() => evaluate('!!document.querySelector("input[name=password]")'), 'login');
        if (await evaluate('document.documentElement.scrollWidth > innerWidth')) throw new Error('Login overflows at ' + width);
        const screenshot = await capture({format: 'png'});
        await writeFile(join(screenshots, `login-${width}.png`), Buffer.from(screenshot.data, 'base64'));
    }
    await evaluate('document.querySelector("input[name=email]").value="browser@example.test"; document.querySelector("input[name=password]").value="browser-smoke-password"; document.querySelector("form").requestSubmit(); true');
    await until(() => evaluate('!!document.querySelector("[data-console-page]")'), 'authenticated dashboard');
    for (const width of [1280, 390]) {
        await page('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
        const screenshot = await capture({format: 'png', captureBeyondViewport: true});
        await writeFile(join(screenshots, `dashboard-${width}.png`), Buffer.from(screenshot.data, 'base64'));
        if (await evaluate('document.documentElement.scrollWidth > innerWidth')) throw new Error('Dashboard overflows at ' + width + ': ' + await evaluate('JSON.stringify({scroll:document.documentElement.scrollWidth,viewport:innerWidth,elements:[...document.querySelectorAll("body *")].filter(e=>e.getBoundingClientRect().right > innerWidth + 1 && !e.closest(".table-wrap")).slice(0,12).map(e=>({tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right}))})'));
    }
    for (const view of ['buckets', 'keys', 'activity']) {
        await page('Page.navigate', {url: base + '/dashboard?view=' + view});
        await until(() => evaluate(`document.querySelector('.side-link.is-active')?.getAttribute('href').includes('view=${view}')`), view + ' screen');
        for (const width of [1280, 390]) {
            await page('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
            if (await evaluate('document.documentElement.scrollWidth > innerWidth')) throw new Error(view + ' overflows at ' + width);
            const screenshot = await capture({format: 'png', captureBeyondViewport: true});
            await writeFile(join(screenshots, `${view}-${width}.png`), Buffer.from(screenshot.data, 'base64'));
        }
    }
    await page('Page.navigate', {url: base + '/dashboard?view=buckets'});
    await until(() => evaluate('!!document.querySelector("button[data-open-dialog=create-bucket]")'), 'bucket creation');
    await evaluate('document.querySelector("button[data-open-dialog=create-bucket]").click()');
    await until(() => evaluate('document.querySelector("#create-bucket")?.open'), 'create dialog opens');
    if (!(await evaluate('document.querySelector("#create-bucket").contains(document.activeElement)'))) throw new Error('Dialog did not move keyboard focus inside');
    await page('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27});
    await page('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27});
    await until(() => evaluate('!document.querySelector("#create-bucket")?.open'), 'escape closes dialog');
    await evaluate('document.querySelector("[data-nav-toggle]").click()');
    if (!(await evaluate('document.querySelector("[data-nav-toggle]").getAttribute("aria-expanded") === "true"'))) throw new Error('Mobile navigation did not open');
    await evaluate('document.querySelector("[data-nav-close]").click()');
    await page('Page.navigate', {url: base + '/buckets/media?state=trash'});
    await until(() => evaluate('!!document.querySelector("select[name=state]")'), 'bucket inventory');
    if (await evaluate('document.documentElement.scrollWidth > innerWidth')) throw new Error('Bucket inventory overflows on mobile');
    const inventoryScreenshot = await capture({format: 'png', captureBeyondViewport: true});
    await writeFile(join(screenshots, 'inventory-390.png'), Buffer.from(inventoryScreenshot.data, 'base64'));
    if (exceptions.length) throw new Error(exceptions.join('\n'));
    console.log('Browser smoke passed: landing, API docs, login, authenticated dashboard, bucket inventory; desktop and mobile. Screenshots: storage/framework/browser-smoke.');
} finally {
    if (socket?.readyState === WebSocket.OPEN) { try { await call('Browser.close'); } catch {} socket.close(); }
    browser?.kill();
    server?.kill();
    await new Promise(resolve => setTimeout(resolve, 500));
    await rm(temporary, {recursive: true, force: true, maxRetries: 10, retryDelay: 200});
}
