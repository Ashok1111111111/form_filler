// popup.js - AI Form Filler Extension
document.addEventListener('DOMContentLoaded', () => {
    const WEBSITE_URL = 'https://formfiller.ai-workflows.cloud';

    const fillFormBtn     = document.getElementById('fillFormBtn');
    const clearCacheBtn   = document.getElementById('clearCacheBtn');
    const dataPreview     = document.getElementById('dataPreview');
    const previewSource   = document.getElementById('previewSource');
    const previewName     = document.getElementById('previewName');
    const previewEmail    = document.getElementById('previewEmail');
    const previewMobile   = document.getElementById('previewMobile');
    const previewFieldCount = document.getElementById('previewFieldCount');
    const statusMessages  = document.getElementById('statusMessages');
    const modeNote = document.getElementById('modeNote');
    const footerNote = document.getElementById('footerNote');

    let loadedCustomerData = null;
    let loadedSessionData  = null;
    let currentUserId      = null;
    let loadedDataSource   = null;

    // ── SERVER PROXY HELPERS ────────────────────────────────────────────────
    // All Firestore reads go through the server to avoid Firebase API key
    // HTTP-referrer restrictions that block chrome-extension:// origins.

    async function serverPost(endpoint, body, fbIdToken = null) {
        const headers = { 'Content-Type': 'application/json' };
        if (fbIdToken) headers.Authorization = `Bearer ${fbIdToken}`;
        const res = await fetch(`${WEBSITE_URL}/${endpoint}`, {
            method: 'POST',
            headers,
            body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) {
            const detail = data?.error || data?.diagnostic?.body?.error?.message || data?.diagnostic?.step || `HTTP ${res.status}`;
            throw new Error(detail);
        }
        return data;
    }

    function showStatus(message, type = 'info') {
        statusMessages.innerHTML = `<div class="text-${type}">${message}</div>`;
        statusMessages.style.display = 'block';
    }

    function updateFillButtonLabel() {
        fillFormBtn.textContent = loadedDataSource === 'scan'
            ? 'Fill Current Form from Session ⚡'
            : 'Fill Saved Customer Data ⚡';
    }

    function updateModeUi() {
        const isScan = loadedDataSource === 'scan';
        if (isScan) {
            modeNote.textContent = 'Session scan received from dashboard. Open the target form and click fill.';
            footerNote.textContent = 'Session data will be cleared automatically after a successful fill.';
        } else {
            modeNote.textContent = 'Open the dashboard, scan a document, review the extracted fields, then send the session to this extension.';
            footerNote.textContent = 'You can also continue with locally saved extension data if it is already available.';
        }
    }

    function getFieldCount(data) {
        return Object.keys(data || {}).filter((key) => data[key] !== null && data[key] !== undefined && data[key] !== '').length;
    }

    function loadActiveData(data, source = 'customer', sessionData = null) {
        loadedCustomerData = data;
        loadedSessionData = source === 'scan' ? sessionData : null;
        loadedDataSource = data ? source : null;
        updatePreview(data);
    }

    function updatePreview(data) {
        updateFillButtonLabel();
        updateModeUi();
        if (data) {
            const sourceLabel = loadedDataSource === 'scan' ? 'Session scan' : 'Saved customer profile';
            previewSource.textContent = sourceLabel;
            previewName.textContent   = data.fullName || data.customerName || 'N/A';
            previewEmail.textContent  = data.email || data.customerEmail || data.emailId || 'N/A';
            previewMobile.textContent = data.mobile || data.customerMobile || data.mobileNumber || 'N/A';
            previewFieldCount.textContent = `${getFieldCount(data)} fields`;
            dataPreview.style.display = 'block';
            fillFormBtn.disabled = false;
        } else {
            dataPreview.style.display = 'none';
            fillFormBtn.disabled = true;
        }
    }

    async function getCurrentAuthContext() {
        return new Promise((resolve) => {
            chrome.storage.local.get(['userId', 'fbIdToken'], (result) => resolve({
                userId: result.userId || null,
                fbIdToken: result.fbIdToken || null
            }));
        });
    }

    // Fetch wallet balance — returns object {walletBalance, name} or null
    async function getWalletInfo(userId, fbIdToken) {
        try {
            const data = await serverPost('get-credits.php', { userId }, fbIdToken);
            return data ?? null;
        } catch {
            return null;
        }
    }

    // Deduct 20 paise/field from server after successful fill
    // Also logs the form fill (portal, page title, customer) for Form History tab
    async function deductWallet(userId, fbIdToken, filledCount, tab, agentAi = null, scanAi = null) {
        try {
            const data = await serverPost('deduct-credit.php', {
                userId,
                fieldsFilledCount: filledCount,
                customerId: loadedCustomerData?.customerId || null,
                fillMode: loadedDataSource === 'scan' ? 'session_scan' : 'saved_customer',
                sessionSource: loadedDataSource === 'scan' ? 'dashboard_scan' : '',
                pageTitle:  tab?.title  || '',
                pageUrl:    tab?.url    || '',
                portalName: '',         // auto-derived from URL in PHP
                agentAi,
                scanAi
            }, fbIdToken);
            return data;
        } catch (e) {
            console.error('Deduction failed:', e);
            return null;
        }
    }

    // Show wallet bar
    async function refreshWalletBar() {
        const walletUser = document.getElementById('walletUser');
        const walletAmt  = document.getElementById('walletAmt');
        const authCtx = await getCurrentAuthContext();
        currentUserId = authCtx.userId;
        if (!currentUserId) {
            walletUser.innerHTML = '⚠️ <a href="https://formfiller.ai-workflows.cloud/login.html" target="_blank" style="color:#f5724f;font-weight:700;">Log in to fill forms</a>';
            walletAmt.textContent = '';
            return;
        }
        const info = await getWalletInfo(currentUserId, authCtx.fbIdToken);
        if (info) {
            walletUser.textContent  = info.name ? `👤 ${info.name}` : '👤 Logged in';
            walletAmt.textContent   = `💳 ₹${parseFloat(info.walletBalance).toFixed(2)}`;
            walletAmt.style.color   = info.walletBalance < 0.20 ? '#f44336' : '#8bc34a';
        } else {
            walletUser.textContent = '⚠️ Could not fetch wallet';
            walletAmt.textContent  = '';
        }
    }

    // ── SERVER POST WITH TIMEOUT ───────────────────────────────────────────
    async function serverPostWithTimeout(endpoint, body, timeoutMs = 8000) {
        const ctrl  = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeoutMs);
        try {
            const res = await fetch(`${WEBSITE_URL}/${endpoint}`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(body),
                signal:  ctrl.signal
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        } finally {
            clearTimeout(timer);
        }
    }

    // Minimal fallback mapping when server is unreachable
    function buildFallbackMapping(fields, customerData) {
        const mapping     = {};
        const directKeys  = ['firstName','lastName','fullName','emailId','mobileNumber',
                             'dob','fatherName','motherName','aadhaarNumber','panNumber'];
        for (const field of fields) {
            const label = (field.label + ' ' + field.name + ' ' + field.id).toLowerCase();
            for (const key of directKeys) {
                if (customerData[key] && label.includes(key.toLowerCase())) {
                    mapping[String(field.index)] = key;
                    break;
                }
            }
        }
        return mapping;
    }

    // ── FILL FORM (3-step Agent flow) ──────────────────────────────────────
    fillFormBtn.addEventListener('click', async () => {
        if (!loadedCustomerData) { showStatus('No data loaded.', 'danger'); return; }

        const authCtx = await getCurrentAuthContext();
        currentUserId = authCtx.userId;
        if (!currentUserId) {
            showStatus('❌ Not logged in! Open formfiller.ai-workflows.cloud, log in, then try again.', 'danger');
            setTimeout(() => chrome.tabs.create({ url: `${WEBSITE_URL}/login.html` }), 1500);
            return;
        }

        const info = await getWalletInfo(currentUserId, authCtx.fbIdToken);
        if (info && info.walletBalance < 0.20) {
            showStatus('❌ Wallet empty! Add money to continue.', 'danger');
            setTimeout(() => chrome.tabs.create({ url: `${WEBSITE_URL}/recharge.html` }), 1500);
            return;
        }

        if (!authCtx.fbIdToken) {
            showStatus('❌ Session expired in extension. Open dashboard once, then try again.', 'danger');
            fillFormBtn.disabled = false;
            return;
        }

        fillFormBtn.disabled = true;

        try {
            const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

            // ── STEP 1: Scan form fields ─────────────────────────────────
            showStatus('🔍 Scanning form fields...', 'info');
            const scanResult = await chrome.tabs.sendMessage(tab.id, { action: 'scanFields' });
            if (!scanResult?.success) {
                showStatus(scanResult?.message || '⚠️ No form fields found. Try reloading the page.', 'danger');
                fillFormBtn.disabled = false;
                return;
            }
            const { fields, formSignature } = scanResult;

            // ── STEP 2: Server agent maps fields → customer data ─────────
            showStatus(`🤖 Agent thinking... (${fields.length} fields detected)`, 'info');
            let agentResult;
            try {
                agentResult = await serverPostWithTimeout('form-agent.php', {
                    fields,
                    customerData: loadedCustomerData,
                    formSignature,
                    portal: tab.url || ''
                }, 25000);
            } catch (serverErr) {
                // Server unavailable — use minimal local fallback
                console.warn('Server unavailable, using fallback mapping:', serverErr.message);
                agentResult = {
                    success: true,
                    mapping: buildFallbackMapping(fields, loadedCustomerData),
                    cached:  false,
                    fallback: true
                };
                showStatus('⚡ Server offline — using basic fill...', 'info');
            }

            if (!agentResult?.success || !agentResult?.mapping) {
                showStatus('⚠️ Agent returned no mapping. Please try again.', 'danger');
                fillFormBtn.disabled = false;
                return;
            }

            const cacheLabel = agentResult.cached    ? ' ⚡ (instant — cached)'
                             : agentResult.fallback   ? ' (basic — server offline)'
                             : agentResult.aiUsed     ? ' 🧠 (AI mapped)'
                             : ' (rules matched)';

            // ── STEP 3: Execute mapping — extension fills the form ───────
            showStatus(`✍️ Filling form${cacheLabel}...`, 'info');
            const fillResult = await chrome.tabs.sendMessage(tab.id, {
                action:       'executeMapping',
                mapping:      agentResult.mapping,
                customerData: loadedCustomerData
            });

            if (!fillResult?.success) {
                showStatus('⚠️ Fill failed. Try reloading the page.', 'danger');
                fillFormBtn.disabled = false;
                return;
            }

            // ── STEP 4: Deduct credits + show result ─────────────────────
            const agentAi = agentResult?.metrics?.agentAi || null;
            const scanAi = loadedDataSource === 'scan' ? (loadedSessionData?.metrics?.scanAi || null) : null;
            const deduct   = await deductWallet(currentUserId, authCtx.fbIdToken, fillResult.filledCount, tab, agentAi, scanAi);
            if (!deduct?.success) {
                showStatus('⚠️ Form filled, but wallet/dashboard update failed. Please try again and check server logs.', 'danger');
                fillFormBtn.disabled = false;
                return;
            }
            const newBal   = deduct?.walletBalance ?? null;
            const deducted = deduct?.deducted      ?? null;
            const margin   = deduct?.grossMarginRs ?? null;
            const costStr  = deducted !== null
                ? `₹${parseFloat(deducted).toFixed(2)} (${fillResult.filledCount} fields × 20p)` : '';
            const marginStr = margin !== null
                ? ` | Margin: ₹${parseFloat(margin).toFixed(2)}` : '';
            const balStr   = newBal !== null
                ? ` | Wallet: ₹${parseFloat(newBal).toFixed(2)}` : '';
            showStatus(`🎉 Done! ${fillResult.filledCount} fields filled. ${costStr} deducted.${marginStr}${balStr}`, 'success');

            if (loadedDataSource === 'scan') {
                await new Promise((resolve) => chrome.storage.local.remove(['sessionScanData'], resolve));
                loadActiveData(null);
                showStatus(`🎉 Done! ${fillResult.filledCount} fields filled. ${costStr} deducted.${marginStr}${balStr} Session data cleared.`, 'success');
            }

            if (newBal !== null) {
                document.getElementById('walletAmt').textContent = `💳 ₹${parseFloat(newBal).toFixed(2)}`;
                document.getElementById('walletAmt').style.color = newBal < 0.20 ? '#f44336' : '#8bc34a';
            }

        } catch (error) {
            showStatus(`Error: ${error.message}`, 'danger');
        } finally {
            fillFormBtn.disabled = false;
        }
    });

    // ── CLEAR CACHE — now clears server-side cache via a different approach ──
    // The clearCache button has been repurposed: clears locally stored customer data
        if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', async () => {
            if (confirm('Clear saved customer data and any session scan from this browser?')) {
                await new Promise(r => chrome.storage.local.remove(['customerData', 'sessionScanData'], r));
                loadActiveData(null);
                showStatus('🗑️ Local data cleared.', 'success');
            }
        });
    }

    // ── INIT — restore last customer data + wallet bar + scan documents ──
    (async () => {
        await refreshWalletBar();
        chrome.storage.local.get(['customerData', 'sessionScanData'], (result) => {
            if (result.sessionScanData?.fields) {
                loadActiveData(result.sessionScanData.fields, 'scan', result.sessionScanData);
                showStatus(`📄 Session scan ready: ${result.sessionScanData.fieldCount || getFieldCount(result.sessionScanData.fields)} fields received from dashboard.`, 'success');
                return;
            }
            if (result.customerData) {
                loadActiveData(result.customerData, 'customer', null);
                showStatus('Saved customer data available as fallback.', 'info');
                return;
            }
            updateModeUi();
        });
    })();

    chrome.storage.onChanged.addListener((changes, areaName) => {
        if (areaName !== 'local') return;

        if (changes.sessionScanData) {
            const nextSession = changes.sessionScanData.newValue;
            if (nextSession?.fields) {
                loadActiveData(nextSession.fields, 'scan', nextSession);
                showStatus(`📄 Session scan ready: ${nextSession.fieldCount || getFieldCount(nextSession.fields)} fields received from dashboard.`, 'success');
                return;
            }
            if (!changes.sessionScanData.newValue) {
                loadActiveData(null);
                updateModeUi();
            }
        }

        if (changes.customerData && !loadedCustomerData) {
            const nextCustomer = changes.customerData.newValue;
            if (nextCustomer) {
                loadActiveData(nextCustomer, 'customer', null);
                showStatus('Saved customer data available as fallback.', 'info');
            }
        }
    });
});
