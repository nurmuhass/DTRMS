const root = document.documentElement;
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
let themePreference;
try {
    themePreference = localStorage.getItem('dtrms-theme');
} catch {
    // The browser can disable persistent storage.
}
function applyTheme() {
    root.dataset.theme = ['light', 'dark'].includes(themePreference) ? themePreference : (systemTheme.matches ? 'dark' : 'light');
}
applyTheme();

document.addEventListener('DOMContentLoaded', initializePortal);

function initializePortal() {
    document.querySelector('[data-print-report]')?.addEventListener('click', () => window.print());
    const themeButton = document.querySelector('[data-theme-toggle]');
    function updateTheme() {
        applyTheme();
        if (themeButton) {
            themeButton.textContent = root.dataset.theme === 'dark' ? 'Use light theme' : 'Use dark theme';
        }
        document.dispatchEvent(new Event('portal-theme-change'));
    }
    systemTheme.addEventListener('change', updateTheme);
    if (themeButton) {
        themeButton.hidden = false;
        updateTheme();
        themeButton.addEventListener('click', () => {
            themePreference = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('dtrms-theme', themePreference); } catch {}
            updateTheme();
        });
    }

    const popovers = [...document.querySelectorAll('.topbar details')];
    for (const popover of popovers) {
        popover.addEventListener('toggle', () => {
            if (popover.open) popovers.filter((item) => item !== popover).forEach((item) => { item.open = false; });
        });
    }
    document.addEventListener('click', (event) => {
        popovers.filter((item) => item.open && !item.contains(event.target)).forEach((item) => { item.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        const open = popovers.find((item) => item.open && item.contains(document.activeElement));
        if (open && event.key === 'Escape') {
            open.open = false;
            open.querySelector('summary').focus();
        }
    });

    const sidebar = document.querySelector('#sidebar');
    const menu = document.querySelector('.menu-toggle');

    if (sidebar && menu) {
        document.documentElement.classList.add('has-js');

        function setMenuOpen(open) {
            sidebar.classList.toggle('is-open', open);
            menu.setAttribute('aria-expanded', String(open));
        }

        menu.addEventListener('click', () => {
            const open = menu.getAttribute('aria-expanded') === 'false';
            setMenuOpen(open);
            if (open) sidebar.querySelector('nav a')?.focus();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true' && menu.offsetWidth > 0) {
                setMenuOpen(false);
                menu.focus();
            }
        });
    }

    const confirmation = document.querySelector('#confirm-action');
    if (typeof HTMLDialogElement !== 'undefined' && confirmation instanceof HTMLDialogElement
        && typeof confirmation.showModal === 'function') {
        let pendingForm = null;
        let pendingSubmitter = null;
        let confirmedForm = null;

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || confirmedForm === form) {
                return;
            }
            const message = event.submitter?.dataset.confirm || form.dataset.confirm;
            if (!message) {
                return;
            }
            event.preventDefault();
            pendingForm = form;
            pendingSubmitter = event.submitter;
            confirmation.returnValue = '';
            const selectedDates = event.submitter?.hasAttribute('data-weekly-confirm')
                ? new FormData(form).getAll('days[]') : [];
            confirmation.querySelector('[data-confirm-message]').textContent = selectedDates.length
                ? message + ' Selected dates: ' + selectedDates.join(', ') + '.' : message;
            confirmation.querySelector('[data-confirm-submit]').textContent = event.submitter?.textContent.trim() || 'Confirm';
            confirmation.showModal();
        });

        confirmation.addEventListener('close', () => {
            const form = pendingForm;
            const submitter = pendingSubmitter;
            pendingForm = null;
            pendingSubmitter = null;
            if (form && confirmation.returnValue === 'confirm') {
                confirmedForm = form;
                form.requestSubmit(submitter || undefined);
                confirmedForm = null;
            } else {
                submitter?.focus();
            }
        });
    }

    const notifications = document.querySelector('[data-notifications]');
    if (notifications) {
        const list = notifications.querySelector('[data-notification-items]');
        const count = notifications.querySelector('[data-unread]');
        const status = notifications.querySelector('[data-notification-status]');
        let requestNumber = 0;

        function renderNotifications(data) {
            count.hidden = data.unread === 0;
            count.textContent = data.unread > 99 ? '99+' : String(data.unread);
            notifications.querySelector('summary').setAttribute('aria-label', data.unread ? `Notifications, ${data.unread} unread` : 'Notifications');
            document.querySelectorAll('[data-mark-all]').forEach((button) => { button.disabled = data.unread === 0; });
            list.replaceChildren();
            list.setAttribute('aria-busy', 'false');
            status.textContent = data.items.length ? '' : 'No notifications.';
            for (const item of data.items) {
                const row = document.createElement('li');
                row.classList.toggle('is-unread', item.unread);
                const label = document.createElement('strong');
                label.textContent = item.message;
                row.append(label);
                const date = document.createElement('small');
                date.textContent = item.date;
                row.append(date);
                if (item.url) {
                    const link = document.createElement('a');
                    link.href = item.url;
                    link.textContent = 'Open';
                    row.append(link);
                }
                if (item.unread) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'button button-secondary';
                    button.textContent = 'Mark as read';
                    button.addEventListener('click', () => saveRead('/notifications/read',
                        new URLSearchParams({ csrf: notifications.dataset.csrf, id: item.id }), button));
                    row.append(button);
                }
                list.append(row);
            }
        }

        async function notificationRequest(url, options = {}) {
            const current = ++requestNumber;
            const response = await fetch(url, { headers: { Accept: 'application/json' }, ...options });
            const isJson = response.headers.get('Content-Type')?.includes('application/json');
            const data = isJson ? await response.json() : null;
            if (!response.ok || !data) {
                throw new Error(data?.error?.message || 'Notifications are temporarily unavailable.');
            }
            if (current === requestNumber) {
                renderNotifications(data);
            }
        }

        function refreshNotifications() {
            notificationRequest('/notifications/feed').catch(error => {
                list.setAttribute('aria-busy', 'false');
                status.textContent = error.message;
            });
        }

        async function saveRead(url, body, button) {
            button.disabled = true;
            const feedback = document.querySelector('[data-inbox-feedback]') || status;
            try {
                await notificationRequest(url, { method: 'POST', body });
                feedback.textContent = 'Read status updated.';
                const id = body.get('id');
                for (const row of document.querySelectorAll('[data-notification-row]')) {
                    if (id === null || row.dataset.notificationRow === id) {
                        row.querySelector('[data-notification-write]')?.remove();
                        row.classList.remove('is-unread');
                    }
                }
            } catch (error) {
                feedback.textContent = error.message;
                button.disabled = false;
            }
        }

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (form instanceof HTMLFormElement && form.hasAttribute('data-notification-write')) {
                const button = form.querySelector('button');
                if (button) {
                    event.preventDefault();
                    saveRead(form.action, new URLSearchParams(new FormData(form)), button);
                }
            }
        });
        notifications.addEventListener('toggle', () => {
            if (notifications.open) {
                refreshNotifications();
            }
        });
        refreshNotifications();
        window.setInterval(() => {
            if (!document.hidden) {
                refreshNotifications();
            }
        }, 60000);
    }

    const reportingCharts = document.querySelector('[data-report-charts]');
    if (reportingCharts && typeof window.Chart === 'function') {
        const charts = JSON.parse(reportingCharts.dataset.reportCharts);
        const chartPalette = () => {
            const style = getComputedStyle(root);
            return ['--chart-primary', '--chart-secondary', '--chart-tertiary', '--muted', '--border']
                .map((name) => style.getPropertyValue(name).trim());
        };

        for (const canvas of reportingCharts.querySelectorAll('canvas[data-chart]')) {
            const [type, labels, series] = charts[canvas.dataset.chart];
            const horizontal = canvas.dataset.chart === 'departments';
            const colors = chartPalette();
            const chart = new window.Chart(canvas, {
                type,
                data: {
                    labels,
                    datasets: Object.entries(series).map(([label, values], index) => ({
                        label,
                        data: values,
                        borderColor: colors[index],
                        backgroundColor: colors[index],
                        borderRadius: type === 'bar' ? 3 : 0,
                        tension: 0.25,
                        pointRadius: labels.length > 40 ? 0 : 2,
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: horizontal ? 'y' : 'x',
                    animation: false,
                    color: colors[3],
                    plugins: { legend: { display: Object.keys(series).length > 1 } },
                    scales: {
                        [horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { precision: 0 } },
                        [horizontal ? 'y' : 'x']: { ticks: { maxTicksLimit: horizontal ? 10 : 12 } },
                    },
                },
            });
            function recolor() {
                const palette = chartPalette();
                chart.options.color = palette[3];
                for (const axis of Object.values(chart.options.scales)) {
                    axis.ticks.color = palette[3];
                    axis.grid.color = palette[4];
                }
                chart.data.datasets.forEach((dataset, index) => {
                    dataset.borderColor = palette[index];
                    dataset.backgroundColor = palette[index];
                });
                chart.update('none');
            }
            recolor();
            document.addEventListener('portal-theme-change', recolor);
        }
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.method === 'dialog'
            || form.target || form.hasAttribute('data-download')) return;
        if (form.hasAttribute('data-submitting')) {
            event.preventDefault();
            return;
        }
        form.setAttribute('data-submitting', '');
        form.setAttribute('aria-busy', 'true');
        event.submitter?.classList.add('is-submitting');
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-submitting]').forEach((form) => {
            form.removeAttribute('data-submitting');
            form.removeAttribute('aria-busy');
            form.querySelector('.is-submitting')?.classList.remove('is-submitting');
        });
    });
}
