/*
 * LADDER: shared page behaviour (vanilla JS, no dependencies).
 *
 * - Sidebar menu button on narrow screens
 * - Toasts for flash messages (dismiss, auto-hide)
 * - data-confirm="..." on forms and links: an accessible confirm dialog
 * - data-toggle="#id" buttons that show/hide a panel
 * - Data tables: search, sortable columns and paging
 *
 * Everything degrades gracefully: without JS, forms submit and tables show
 * all rows. Nothing here is trusted for security; the server checks it all.
 */
(function () {
    'use strict';

    // ---- Sidebar ---------------------------------------------------------------
    const sidebar = document.getElementById('app-sidebar');
    const burger = sidebar && sidebar.querySelector('.nav-burger');
    if (burger) {
        const setMenu = open => {
            sidebar.classList.toggle('is-open', open);
            document.documentElement.classList.toggle('nav-open', open); // stops the page scrolling behind the menu
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
            burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        };
        burger.addEventListener('click', () => setMenu(!sidebar.classList.contains('is-open')));
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setMenu(false);
                burger.focus();
            }
        });
        // Widening past the mobile layout closes the menu and unlocks scrolling.
        window.matchMedia('(min-width: 1025px)').addEventListener('change', e => e.matches && setMenu(false));
    }

    // ---- Toasts ------------------------------------------------------------------
    function dismissToast(toast) {
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), 220);
    }
    document.querySelectorAll('.toast').forEach(toast => {
        toast.querySelector('.toast-close')?.addEventListener('click', () => dismissToast(toast));
        const isError = toast.classList.contains('toast-error');
        setTimeout(() => toast.isConnected && dismissToast(toast), isError ? 12000 : 6000);
    });

    // ---- Confirm dialog -------------------------------------------------------------
    let dialog = null;
    function getDialog() {
        if (dialog) return dialog;
        dialog = document.createElement('dialog');
        dialog.className = 'app-dialog';
        dialog.setAttribute('aria-labelledby', 'app-dialog-title');
        dialog.innerHTML =
            '<form method="dialog">' +
            '<div class="dialog-body"><h2 id="app-dialog-title">Please confirm</h2><p></p></div>' +
            '<div class="dialog-actions">' +
            '<button value="cancel" class="btn btn-outline">Cancel</button>' +
            '<button value="ok" class="btn">Confirm</button>' +
            '</div></form>';
        document.body.appendChild(dialog);
        return dialog;
    }

    /** Ask; resolve true if confirmed. Falls back to window.confirm. */
    function ask(message, danger) {
        const d = getDialog();
        if (typeof d.showModal !== 'function') {
            return Promise.resolve(window.confirm(message));
        }
        d.querySelector('p').textContent = message;
        const ok = d.querySelector('button[value="ok"]');
        ok.className = danger ? 'btn btn-danger' : 'btn';
        return new Promise(resolve => {
            d.addEventListener('close', () => resolve(d.returnValue === 'ok'), { once: true });
            d.returnValue = '';
            d.showModal();
            d.querySelector('button[value="cancel"]').focus();
        });
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
        event.preventDefault();
        const submitter = event.submitter;
        ask(form.dataset.confirm, form.dataset.danger !== undefined).then(yes => {
            if (!yes) return;
            form.dataset.confirmed = '1';
            if (submitter && submitter.name) {
                // Keep the clicked button's name/value in the submission.
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            form.submit();
        });
    });

    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-confirm]');
        if (!link) return;
        event.preventDefault();
        ask(link.dataset.confirm, link.dataset.danger !== undefined).then(yes => {
            if (yes) window.location.href = link.href;
        });
    });

    // ---- Print / Save as PDF buttons --------------------------------------------------
    document.querySelectorAll('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));

    // ---- Toggle panels ------------------------------------------------------------------
    document.querySelectorAll('[data-toggle]').forEach(button => {
        const target = document.querySelector(button.dataset.toggle);
        if (!target) return;
        button.setAttribute('aria-controls', target.id);
        button.setAttribute('aria-expanded', target.classList.contains('hidden-panel') ? 'false' : 'true');
        button.addEventListener('click', () => {
            const hidden = target.classList.toggle('hidden-panel');
            button.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            if (!hidden) target.querySelector('input, select, textarea')?.focus();
        });
    });

    // ---- Data tables: search, sort, paging -----------------------------------------------
    const PAGE_SIZE = 20;

    function cellValue(row, index) {
        const cell = row.cells[index];
        if (!cell) return '';
        const raw = (cell.dataset.sort ?? cell.textContent).trim();
        const num = raw.replace(/[%,\s]/g, '').replace(/^#/, '');
        return num !== '' && !isNaN(num) && /^-?[\d.]+$/.test(num) ? parseFloat(num) : raw.toLowerCase();
    }

    function enhanceTable(table) {
        const body = table.tBodies[0];
        if (!body || table.dataset.enhance === 'off') return;
        const rows = Array.from(body.rows).filter(r => r.cells.length > 1);
        if (rows.length < 2) return;

        const state = { query: '', page: 1, sortIndex: -1, dir: 1 };
        const wrap = table.closest('.table-wrap') || table;

        // Search and count (only when there is enough to search). Tables with
        // their own filter use data-enhance="sort": sorting only.
        const sortOnly = table.dataset.enhance === 'sort';
        let tools = null, count = null, pager = null;
        if (!sortOnly && rows.length >= 6) {
            tools = document.createElement('div');
            tools.className = 'table-tools';
            const label = table.getAttribute('aria-label') || 'table';
            tools.innerHTML = '<input type="search" placeholder="Search…" aria-label="Search ' + label.replace(/"/g, '') + '"><span class="table-count" aria-live="polite"></span>';
            wrap.parentNode.insertBefore(tools, wrap);
            count = tools.querySelector('.table-count');
            tools.querySelector('input').addEventListener('input', e => {
                state.query = e.target.value.trim().toLowerCase();
                state.page = 1;
                render();
            });
        }
        if (!sortOnly && rows.length > PAGE_SIZE) {
            pager = document.createElement('nav');
            pager.className = 'table-pager';
            pager.setAttribute('aria-label', 'Table pages');
            wrap.parentNode.insertBefore(pager, wrap.nextSibling);
        }

        // Sortable headers.
        const headRow = table.tHead && table.tHead.rows[0];
        if (headRow) {
            Array.from(headRow.cells).forEach((th, index) => {
                if (th.dataset.sortable === 'false' || th.textContent.trim() === '') return;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'th-sort';
                button.textContent = th.textContent.trim();
                th.textContent = '';
                th.appendChild(button);
                th.setAttribute('aria-sort', 'none');
                button.addEventListener('click', () => {
                    state.dir = state.sortIndex === index ? -state.dir : 1;
                    state.sortIndex = index;
                    Array.from(headRow.cells).forEach(c => c.hasAttribute('aria-sort') && c.setAttribute('aria-sort', 'none'));
                    th.setAttribute('aria-sort', state.dir === 1 ? 'ascending' : 'descending');
                    render();
                });
            });
        }

        function render() {
            let visible = rows.filter(r => !state.query || r.textContent.toLowerCase().includes(state.query));
            if (state.sortIndex >= 0) {
                visible = visible.slice().sort((a, b) => {
                    const x = cellValue(a, state.sortIndex), y = cellValue(b, state.sortIndex);
                    return (x > y ? 1 : x < y ? -1 : 0) * state.dir;
                });
                visible.forEach(r => body.appendChild(r));
            }
            const pages = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
            state.page = Math.min(state.page, pages);
            const start = pager ? (state.page - 1) * PAGE_SIZE : 0;
            const end = pager ? start + PAGE_SIZE : visible.length;
            const shown = new Set(visible.slice(start, end));
            if (!sortOnly) rows.forEach(r => { r.hidden = !shown.has(r); });

            if (count) {
                count.textContent = visible.length === rows.length
                    ? rows.length + ' rows'
                    : visible.length + ' of ' + rows.length + ' rows';
            }
            if (pager) {
                pager.innerHTML = '';
                const add = (text, page, opts = {}) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = text;
                    if (opts.label) b.setAttribute('aria-label', opts.label);
                    if (opts.current) b.setAttribute('aria-current', 'page');
                    b.disabled = !!opts.disabled;
                    b.addEventListener('click', () => { state.page = page; render(); });
                    pager.appendChild(b);
                };
                add('‹', state.page - 1, { label: 'Previous page', disabled: state.page === 1 });
                for (let p = 1; p <= pages; p++) {
                    if (pages > 7 && Math.abs(p - state.page) > 2 && p !== 1 && p !== pages) continue;
                    add(String(p), p, { current: p === state.page, label: 'Page ' + p });
                }
                add('›', state.page + 1, { label: 'Next page', disabled: state.page === pages });
                pager.hidden = pages === 1;
            }
        }
        render();
    }

    document.querySelectorAll('table.data-table').forEach(enhanceTable);
})();
