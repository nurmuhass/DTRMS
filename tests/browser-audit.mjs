import assert from 'node:assert/strict';
import { setTimeout as sleep } from 'node:timers/promises';

const origin = process.argv[2];
assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/);
const debugPort = Number(process.argv[3]);
assert(Number.isInteger(debugPort) && debugPort > 0 && debugPort < 65536);
const password = process.env.DTRMS_BROWSER_PASSWORD;
assert(password);

let target;
for (let attempt = 0; attempt < 35 && !target; attempt++) {
    try {
        const response = await fetch('http://127.0.0.1:' + debugPort + '/json/list');
        target = (await response.json()).find(entry => entry.type === 'page');
    } catch {
        await sleep(200);
    }
}
assert(target, 'Edge DevTools page is available');
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});
let sequence = 0;
const pending = new Map();
const exceptions = [];
let loaded;
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.id) {
        const promise = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) promise.reject(new Error(message.error.message));
        else promise.resolve(message.result);
    } else if (message.method === 'Page.loadEventFired') {
        loaded?.();
    } else if (message.method === 'Runtime.exceptionThrown') {
        exceptions.push(message.params.exceptionDetails.text);
    }
});
function send(method, params = {}) {
    const id = ++sequence;
    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
function waitForPage() {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('page load timed out')), 12000);
        loaded = () => {
            clearTimeout(timer);
            loaded = null;
            resolve();
        };
    });
}
async function navigate(path) {
    const ready = waitForPage();
    await send('Page.navigate', { url: origin + path });
    await ready;
}
async function evaluate(expression) {
    const response = await send('Runtime.evaluate', { expression, returnByValue: true });
    assert(!response.exceptionDetails, 'test evaluated successfully');
    return response.result.value;
}

const rolePages = {
    staff: ['/staff', '/staff/schedule', '/staff/reports', '/staff/reports/edit', '/notifications', '/reports/staff-record'],
    supervisor: ['/supervisor', '/supervisor/reports', '/supervisor/history'],
    hr_officer: ['/hr-officer', '/hr/reports', '/hr/reports/approved'],
    hr_manager: ['/hr-manager', '/hr-manager/staff', '/hr-manager/departments', '/hr-manager/weekly-schedules',
        '/hr-manager/submissions', '/hr-manager/leaves?mode=calendar', '/reports/analytics'],
    admin: ['/administration', '/administration/settings', '/administration/roles'],
};

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    let layouts = 0;
    for (const [role, pages] of Object.entries(rolePages)) {
        await send('Network.clearBrowserCookies');
        await navigate('/login');
        const signedIn = waitForPage();
        await evaluate(`(() => {
            const form = document.querySelector('form[action="/login"]');
            form.email.value = ${JSON.stringify(role + '@example.test')};
            form.password.value = ${JSON.stringify(password)};
            form.submit();
        })()`);
        await signedIn;
        for (const width of [1440, 820, 390]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
            for (const path of pages) {
                await navigate(path);
                const state = await evaluate(`({
                    path: location.pathname,
                    heading: document.querySelector('main h1')?.textContent.trim(),
                    status: performance.getEntriesByType('navigation')[0].responseStatus,
                    width: document.documentElement.clientWidth,
                    content: document.documentElement.scrollWidth
                })`);
                assert.equal(state.path, path.split('?')[0]);
                assert.equal(state.status, 200, JSON.stringify({ path, state }));
                assert(state.heading && !/denied|server error|page not found/i.test(state.heading), JSON.stringify({ path, state }));
                assert(state.content <= state.width + 1, JSON.stringify({ path, width, state }));
                layouts++;
            }
            if (width === 1440) {
                assert(await evaluate(`(() => {
                    const toggle = document.querySelector('[data-theme-toggle]');
                    const root = document.documentElement;
                    const old = root.dataset.theme;
                    toggle.click();
                    const changed = root.dataset.theme !== old;
                    toggle.click();
                    return changed && root.dataset.theme === old;
                })()`), 'light and dark theme toggle works');
            }
            if (width === 390) {
                assert(await evaluate(`(() => {
                    const toggle = document.querySelector('.menu-toggle');
                    toggle.click();
                    const opened = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.click();
                    return opened && toggle.getAttribute('aria-expanded') === 'false';
                })()`), 'mobile sidebar opens and closes');
            }
        }
    }
    assert.deepEqual(exceptions, [], 'no uncaught browser JavaScript exceptions');
    console.log('PASS: ' + layouts + ' authenticated page and viewport combinations, plus five mobile sidebar and five theme checks');
} finally {
    socket.close();
}
