/* ============================================================
   wallet.js — InvestHub Wallet Frontend
   ============================================================ */

'use strict';

// ── State ────────────────────────────────────────────────────
const state = {
    balance:         window.WALLET_BALANCE || 0,
    txnOffset:       10,
    totalTxns:       0,
    activeFilter:    'all',
    selectedStartup: null,
    recipientUser:   null,
    lookupTimer:     null,
    startups:        [],
};

// ── DOM refs ─────────────────────────────────────────────────
const $ = id => document.getElementById(id);

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initFilters();
    initQuickAmounts();
    loadStats();
    checkTxnCount();
    autoHideToast();

    // After a YoCo payment redirect, the webhook and the successUrl redirect
    // race each other — the user often lands here before the webhook has been
    // processed and the balance credited. Poll until the balance rises, then stop.
    const params = new URLSearchParams(window.location.search);
    if (params.get('deposit') === 'success') {
        pollForDeposit();
    }
});

// ── Toast ────────────────────────────────────────────────────
function autoHideToast() {
    const toast = $('toast');
    if (toast) setTimeout(() => toast.remove(), 5000);
}

function showToast(msg, type = 'success') {
    const icons = {
        success: '<svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>',
        error:   '<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        warn:    '<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    };
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const div = document.createElement('div');
    div.className = `toast toast--${type}`;
    div.innerHTML = (icons[type] || '') + msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}

// ── Filters ──────────────────────────────────────────────────
function initFilters() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('filter-btn--active'));
            btn.classList.add('filter-btn--active');
            state.activeFilter = btn.dataset.filter;
            filterTransactions(state.activeFilter);
        });
    });
}

function filterTransactions(filter) {
    document.querySelectorAll('.txn-item').forEach(item => {
        const show = filter === 'all' || item.dataset.type === filter;
        item.style.display = show ? '' : 'none';
    });
}

// ── Quick amounts ────────────────────────────────────────────
function initQuickAmounts() {
    document.querySelectorAll('.quick-amt').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.quick-amt').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            $('depositAmount').value = btn.dataset.amount;
        });
    });
}

// ── Stats ────────────────────────────────────────────────────
async function loadStats() {
    try {
        const res  = await api('GET', 'transactions', null, { limit: 200, offset: 0 });
        const txns = res.transactions || [];

        let deposited = 0, invested = 0, transferred = 0;
        txns.forEach(t => {
            const amt = parseFloat(t.amount);
            if (t.reference_type === 'deposit')                                  deposited   += amt;
            if (t.reference_type === 'investment')                               invested    += amt;
            if (t.reference_type === 'transfer' && t.type === 'debit')          transferred += amt;
        });

        $('statDeposited').textContent   = fmtZAR(deposited);
        $('statInvested').textContent    = fmtZAR(invested);
        $('statTransferred').textContent = fmtZAR(transferred);
        state.totalTxns = res.total;
    } catch { /* silent */ }
}

async function checkTxnCount() {
    try {
        const res = await api('GET', 'transactions', null, { limit: 1, offset: 0 });
        state.totalTxns = res.total || 0;
        if (state.totalTxns > 10) {
            $('loadMoreWrap').style.display = '';
            $('loadMoreBtn').addEventListener('click', loadMore);
        }
    } catch { /* silent */ }
}

// ── Balance refresh ──────────────────────────────────────────
async function refreshWallet() {
    try {
        const data = await api('GET', 'wallet');
        state.balance = parseFloat(data.balance);
        $('balanceValue').textContent     = formatNumber(state.balance);
        $('investAvailBal').textContent   = formatNumber(state.balance);
        $('transferAvailBal').textContent = formatNumber(state.balance);
        reloadTransactions();
    } catch { /* silent */ }
}

async function reloadTransactions() {
    try {
        const res  = await api('GET', 'transactions', null, { limit: 10, offset: 0 });
        renderTransactions(res.transactions, true);
        state.txnOffset = 10;
        state.totalTxns = res.total;
        $('loadMoreWrap').style.display = res.total > 10 ? '' : 'none';
    } catch { /* silent */ }
}

// ── Load more ────────────────────────────────────────────────
async function loadMore() {
    const btn = $('loadMoreBtn');
    btn.textContent = 'Loading…';
    btn.disabled = true;

    try {
        const res  = await api('GET', 'transactions', null, { limit: 10, offset: state.txnOffset });
        renderTransactions(res.transactions, false);
        state.txnOffset += 10;
        if (state.txnOffset >= state.totalTxns) {
            $('loadMoreWrap').style.display = 'none';
        }
    } catch {
        showToast('Failed to load more transactions.', 'error');
    } finally {
        btn.textContent = 'Load more transactions';
        btn.disabled = false;
    }
}

function renderTransactions(txns, replace) {
    const list = $('txnList');
    if (replace) {
        if (!txns.length) {
            list.innerHTML = `
            <div class="txn-empty">
                <svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="28" stroke-width="2"/><path d="M22 32h20M32 22v20" stroke-width="2"/></svg>
                <p>No transactions yet.</p>
                <small>Make your first deposit to get started.</small>
            </div>`;
            return;
        }
        list.innerHTML = '';
    }

    const iconMap = {
        deposit:           `<svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>`,
        investment:        `<svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>`,
        transfer:          `<svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>`,
        system_adjustment: `<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>`,
    };

    txns.forEach(t => {
        const isCredit = t.type === 'credit';
        const div = document.createElement('div');
        div.className = `txn-item txn-item--${t.type}`;
        div.dataset.type = t.reference_type;

        const date = new Date(t.created_at);
        const dateStr = date.toLocaleDateString('en-ZA', { day:'2-digit', month:'short', year:'numeric' })
                      + ' · ' + date.toLocaleTimeString('en-ZA', { hour:'2-digit', minute:'2-digit' });

        div.innerHTML = `
            <div class="txn-item__icon txn-icon--${t.reference_type}">${iconMap[t.reference_type] || ''}</div>
            <div class="txn-item__info">
                <span class="txn-item__desc">${esc(t.description || t.reference_type)}</span>
                ${t.counterparty ? `<span class="txn-item__sub">${esc(t.counterparty)}</span>` : ''}
                <span class="txn-item__ref">${esc(t.transaction_reference)}</span>
            </div>
            <div class="txn-item__right">
                <span class="txn-item__amount ${isCredit ? 'amount--credit' : 'amount--debit'}">
                    ${isCredit ? '+' : '−'}R ${formatNumber(parseFloat(t.amount))}
                </span>
                <span class="txn-item__date">${dateStr}</span>
            </div>
        `;
        list.appendChild(div);
    });

    filterTransactions(state.activeFilter);
}

// ── Modal system ─────────────────────────────────────────────
function openModal(id) {
    closeModal();
    document.getElementById('modalOverlay').classList.add('is-open');
    const modal = document.getElementById('modal-' + id);
    if (!modal) return;
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    if (id === 'invest') loadStartups();
}

function closeModal() {
    document.querySelectorAll('.modal.is-open').forEach(m => m.classList.remove('is-open'));
    document.getElementById('modalOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
    resetForms();
}

function resetForms() {
    ['depositAmount', 'investAmount', 'investNote', 'transferRecipient', 'transferAmount', 'transferNote']
        .forEach(id => { const el = $(id); if (el) el.value = ''; });
    document.querySelectorAll('.quick-amt').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('.field-error').forEach(e => e.remove());
    const rp = $('recipientPreview'); if (rp) rp.innerHTML = '';

    // BUG FIX: cancel any in-flight recipient lookup timer so it doesn't
    // fire after the modal closes and corrupt state.recipientUser
    clearTimeout(state.lookupTimer);
    state.lookupTimer  = null;

    state.selectedStartup = null;
    state.recipientUser   = null;
    document.querySelectorAll('.startup-option').forEach(o => o.classList.remove('selected'));
    $('investSubmitBtn').disabled = true;
    $('startupSelected').style.display = 'none';
}

// ── Post-payment balance polling ────────────────────────────
// YoCo fires the successUrl redirect and the webhook at nearly the same
// time. The user typically lands on this page before the webhook has been
// processed by the server, so the server-rendered balance is still the old
// one. Poll the wallet API until the balance increases, then update the UI.
async function pollForDeposit() {
    const baseline  = state.balance; // value rendered by PHP at page load
    const maxWait   = 20000;         // give up after 20 seconds
    const interval  = 2000;          // check every 2 seconds
    const started   = Date.now();

    const poll = async () => {
        if (Date.now() - started > maxWait) {
            // Webhook took too long — refresh anyway and let the user see
            // whatever balance the server has at this point.
            refreshWallet();
            return;
        }

        try {
            const data = await api('GET', 'wallet');
            const newBal = parseFloat(data.balance);

            if (newBal > baseline) {
                // Balance has risen — webhook was processed, update the UI
                state.balance = newBal;
                $('balanceValue').textContent     = formatNumber(newBal);
                $('investAvailBal').textContent   = formatNumber(newBal);
                $('transferAvailBal').textContent = formatNumber(newBal);
                reloadTransactions();
                loadStats();
                return; // stop polling
            }
        } catch { /* network hiccup — keep trying */ }

        setTimeout(poll, interval);
    };

    // Start first check after a short initial delay to give the webhook
    // a head start before we even bother hitting the API.
    setTimeout(poll, 1500);
}

// ── DEPOSIT ──────────────────────────────────────────────────
async function initiateDeposit() {
    clearErrors();
    const amount = parseFloat($('depositAmount').value);

    if (!amount || amount < 50) {
        return showError('depositAmount', 'Minimum deposit is R 50.00');
    }
    if (amount > 500000) {
        return showError('depositAmount', 'Maximum deposit is R 500 000.00');
    }

    setLoading('depositSubmitBtn', true);

    try {
        const res = await api('POST', 'deposit/initiate', { amount });

        // Redirect to YoCo hosted checkout
        if (res.redirect_url) {
            window.location.href = res.redirect_url;
            return; // button stays in loading state — page is navigating away
        }

        // Should not happen after the server-side fix, but handle gracefully
        showToast('Checkout session created but no redirect URL returned. Please contact support.', 'error');
        setLoading('depositSubmitBtn', false);
    } catch (err) {
        showToast(err.message || 'Could not initiate deposit. Please try again.', 'error');
        setLoading('depositSubmitBtn', false);
    }
}

// ── STARTUPS ─────────────────────────────────────────────────
async function loadStartups() {
    if (state.startups.length) {
        renderStartups(state.startups);
        return;
    }
    try {
        const res = await api('GET', 'startups');
        state.startups = res.startups || [];
        renderStartups(state.startups);
    } catch {
        $('startupList').innerHTML = '<p class="form-hint" style="padding:8px">Failed to load startups. Please refresh.</p>';
    }
}

function renderStartups(startups) {
    const list = $('startupList');
    list.innerHTML = '';

    if (!startups.length) {
        list.innerHTML = '<p class="form-hint" style="padding:8px">No active startups available.</p>';
        return;
    }

    startups.forEach(s => {
        const div = document.createElement('div');
        div.className = 'startup-option';
        div.dataset.id = s.id;

        const initials = (s.name || '?').split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();
        const logoHtml = s.logo_url
            ? `<img src="${esc(s.logo_url)}" alt="${esc(s.name)}">`
            : initials;

        div.innerHTML = `
            <div class="startup-option__logo">${logoHtml}</div>
            <div>
                <div class="startup-option__name">${esc(s.name)}</div>
                <div class="startup-option__sector">${esc(s.sector || '')}</div>
            </div>
            <div>
                <div class="startup-option__equity">${s.equity_available ? s.equity_available + '% equity' : ''}</div>
                <div class="startup-option__val">${s.valuation ? 'Val: R ' + formatNumber(s.valuation) : ''}</div>
            </div>
        `;

        div.addEventListener('click', () => selectStartup(s, div));
        list.appendChild(div);
    });
}

function selectStartup(startup, el) {
    document.querySelectorAll('.startup-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    state.selectedStartup = startup;

    $('selectedStartupCard').innerHTML = `
        <div style="font-size:.75rem;color:var(--gold);font-family:var(--font-mono);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Selected</div>
        <div style="font-weight:600">${esc(startup.name)}</div>
        ${startup.tagline ? `<div style="font-size:.78rem;color:var(--text-2);margin-top:2px">${esc(startup.tagline)}</div>` : ''}
    `;
    $('startupSelected').style.display = 'block';
    $('investSubmitBtn').disabled = false;
    updateEquityHint();
}

function updateEquityHint() {
    const s = state.selectedStartup;
    if (!s || !s.valuation) { $('investEquityHint').textContent = ''; return; }

    const amt = parseFloat($('investAmount').value) || 0;
    if (!amt) { $('investEquityHint').textContent = 'Enter an amount to see estimated equity.'; return; }

    const eq = ((amt / s.valuation) * 100).toFixed(4);
    $('investEquityHint').textContent = `≈ ${eq}% equity at current valuation of R ${formatNumber(s.valuation)}`;
}

$('investAmount') && $('investAmount').addEventListener('input', updateEquityHint);

async function submitInvestment() {
    clearErrors();
    if (!state.selectedStartup) return showToast('Please select a startup.', 'warn');

    const amount = parseFloat($('investAmount').value);
    if (!amount || amount < 1) return showError('investAmount', 'Enter a valid amount.');
    if (amount > state.balance)  return showError('investAmount', 'Insufficient wallet balance.');

    const note = $('investNote').value.trim();
    const s    = state.selectedStartup;
    const equity = s.valuation ? ((amount / s.valuation) * 100) : null;

    setLoading('investSubmitBtn', true);

    try {
        const res = await api('POST', 'invest', {
            startup_id:     s.id,
            amount,
            equity_percent: equity,
            valuation:      s.valuation || null,
            note,
        });

        state.balance -= amount;
        $('balanceValue').textContent = formatNumber(state.balance);

        closeModal();
        openSuccess(
            'Investment Confirmed',
            `You've invested R ${formatNumber(amount)} in ${s.name}.`,
            res.reference
        );

        loadStats();
    } catch (err) {
        showToast(err.message || 'Investment failed. Please try again.', 'error');
    } finally {
        setLoading('investSubmitBtn', false);
    }
}

// ── TRANSFER ─────────────────────────────────────────────────
function lookupRecipient(email) {
    clearTimeout(state.lookupTimer);
    const preview = $('recipientPreview');
    state.recipientUser = null;

    if (!email || !email.includes('@')) {
        preview.innerHTML = '';
        return;
    }

    preview.innerHTML = '<span class="recipient-searching">Searching…</span>';

    state.lookupTimer = setTimeout(async () => {
        try {
            const res = await api('GET', 'users/lookup', null, { email });
            state.recipientUser = res.user;
            preview.innerHTML = `
                <div class="recipient-found">
                    <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                    <div>
                        <div class="recipient-found__name">${esc(res.user.name)}</div>
                        <div class="recipient-found__email">${esc(res.user.email)}</div>
                    </div>
                </div>`;
        } catch {
            state.recipientUser = null;
            preview.innerHTML = '<span class="recipient-error">User not found on platform.</span>';
        }
    }, 500);
}

async function submitTransfer() {
    clearErrors();

    const email  = $('transferRecipient').value.trim();
    const amount = parseFloat($('transferAmount').value);
    const note   = $('transferNote').value.trim();

    if (!email)               return showError('transferRecipient', 'Enter recipient email.');
    if (!state.recipientUser) return showError('transferRecipient', 'Please wait for recipient lookup to complete.');
    if (!amount || amount < 10) return showError('transferAmount', 'Minimum transfer is R 10.00.');
    if (amount > state.balance) return showError('transferAmount', 'Insufficient wallet balance.');

    setLoading('transferSubmitBtn', true);

    try {
        const res = await api('POST', 'transfer', { recipient: email, amount, note });

        state.balance -= amount;
        $('balanceValue').textContent = formatNumber(state.balance);

        closeModal();
        openSuccess(
            'Transfer Sent',
            `R ${formatNumber(amount)} sent to ${res.recipient}.`,
            res.reference
        );

        loadStats();
    } catch (err) {
        showToast(err.message || 'Transfer failed. Please try again.', 'error');
    } finally {
        setLoading('transferSubmitBtn', false);
    }
}

// ── Success modal ────────────────────────────────────────────
function openSuccess(title, msg, ref) {
    $('successTitle').textContent = title;
    $('successMsg').textContent   = msg;
    $('successRef').textContent   = ref ? 'Ref: ' + ref : '';
    openModal('success');
}

// ── Utility ──────────────────────────────────────────────────

// BUG FIX: previously `await res.json()` was unwrapped — any HTML response
// (server 500, hosting error page, .htaccess block) caused an unhandled
// "Unexpected token '<'" error thrown from the bowels of the fetch chain.
// Now we catch it and surface a clean, actionable error message.
async function api(method, action, body = null, params = {}) {
    const url = new URL('/api.php', window.location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

    const opts = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
    };
    if (body) opts.body = JSON.stringify(body);

    let res;
    try {
        res = await fetch(url.toString(), opts);
    } catch (networkErr) {
        throw new Error('Network error — please check your connection and try again.');
    }

    let data;
    try {
        data = await res.json();
    } catch {
        // Server returned non-JSON (HTML error page, hosting timeout, etc.)
        // Log the status so it's visible in the browser console for debugging.
        console.error('[Wallet] Non-JSON response', res.status, res.statusText, url.toString());
        throw new Error(`Server error (${res.status}). Please try again or contact support.`);
    }

    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

function fmtZAR(n) {
    return 'R ' + formatNumber(n);
}

function formatNumber(n) {
    return parseFloat(n || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function showError(inputId, msg) {
    const input = $(inputId);
    if (!input) return;
    const existing = input.parentElement.querySelector('.field-error');
    if (existing) existing.remove();
    const span = document.createElement('span');
    span.className = 'field-error';
    span.textContent = msg;
    input.parentElement.insertAdjacentElement('afterend', span);
    input.focus();
}

function clearErrors() {
    document.querySelectorAll('.field-error').forEach(e => e.remove());
}

function setLoading(btnId, loading) {
    const btn = $(btnId);
    if (!btn) return;
    btn.disabled = loading;
    const text   = btn.querySelector('.btn__text');
    const loader = btn.querySelector('.btn__loader');
    if (text)   text.style.display   = loading ? 'none' : '';
    if (loader) loader.style.display = loading ? '' : 'none';
}

// Close modal on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
