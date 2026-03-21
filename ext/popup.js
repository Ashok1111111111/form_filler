// popup.js - AI Form Filler Extension
document.addEventListener('DOMContentLoaded', () => {
    const WEBSITE_URL = 'https://ai-workflows.cloud';

    const customerIdInput = document.getElementById('customerId');
    const loadDataBtn     = document.getElementById('loadDataBtn');
    const fillFormBtn     = document.getElementById('fillFormBtn');
    const clearCacheBtn   = document.getElementById('clearCacheBtn');
    const dataPreview     = document.getElementById('dataPreview');
    const previewName     = document.getElementById('previewName');
    const previewEmail    = document.getElementById('previewEmail');
    const previewMobile   = document.getElementById('previewMobile');
    const statusMessages  = document.getElementById('statusMessages');

    let loadedCustomerData = null;
    let currentUserId      = null;

    // ── SERVER PROXY HELPERS ────────────────────────────────────────────────
    // All Firestore reads go through the server to avoid Firebase API key
    // HTTP-referrer restrictions that block chrome-extension:// origins.

    async function serverPost(endpoint, body) {
        const res = await fetch(`${WEBSITE_URL}/${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    function showStatus(message, type = 'info') {
        statusMessages.innerHTML = `<div class="text-${type}">${message}</div>`;
        statusMessages.style.display = 'block';
    }

    function updatePreview(data) {
        if (data) {
            previewName.textContent   = data.fullName || data.customerName || 'N/A';
            previewEmail.textContent  = data.email || data.customerEmail || data.emailId || 'N/A';
            previewMobile.textContent = data.mobile || data.customerMobile || data.mobileNumber || 'N/A';
            dataPreview.style.display = 'block';
            fillFormBtn.disabled = false;
        } else {
            dataPreview.style.display = 'none';
            fillFormBtn.disabled = true;
        }
    }

    async function getCurrentUserId() {
        return new Promise((resolve) => {
            chrome.storage.local.get(['userId'], (result) => resolve(result.userId || null));
        });
    }

    // Fetch wallet balance — returns object {walletBalance, name} or null
    async function getWalletInfo(userId) {
        try {
            const data = await serverPost('get-credits.php', { userId });
            return data ?? null;
        } catch {
            return null;
        }
    }

    // Deduct 2 paise/field from server after successful fill
    // Also logs the form fill (portal, page title, customer) for Form History tab
    async function deductWallet(userId, filledCount, tab) {
        try {
            const customerId = customerIdInput.value.trim() || null;
            const data = await serverPost('deduct-credit.php', {
                userId,
                fieldsFilledCount: filledCount,
                customerId,
                pageTitle:  tab?.title  || '',
                pageUrl:    tab?.url    || '',
                portalName: ''          // auto-derived from URL in PHP
            });
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
        currentUserId = await getCurrentUserId();
        if (!currentUserId) {
            walletUser.innerHTML = '⚠️ <a href="https://ai-workflows.cloud/login.html" target="_blank" style="color:#f5724f;font-weight:700;">Log in to fill forms</a>';
            walletAmt.textContent = '';
            return;
        }
        const info = await getWalletInfo(currentUserId);
        if (info) {
            walletUser.textContent  = info.name ? `👤 ${info.name}` : '👤 Logged in';
            walletAmt.textContent   = `💳 ₹${parseFloat(info.walletBalance).toFixed(2)}`;
            walletAmt.style.color   = info.walletBalance < 0.10 ? '#f44336' : '#8bc34a';
        } else {
            walletUser.textContent = '⚠️ Could not fetch wallet';
            walletAmt.textContent  = '';
        }
    }

    async function fetchCustomerProfileByCustomerId(customerId) {
        try {
            showStatus('Loading customer profile...', 'info');
            const data = await serverPost('get-customer.php', { customerId });
            if (data?.success && data.data) {
                return { success: true, data: data.data };
            }
            return { success: false, message: `Customer "${customerId}" not found.` };
        } catch (error) {
            if (error.message.includes('404')) {
                return { success: false, message: `Customer "${customerId}" not found.` };
            }
            return { success: false, message: `Error: ${error.message}` };
        }
    }

    // ── DOCUMENT TOOL SUGGESTIONS ──────────────────────────────────────────

    function buildToolUrl(doc) {
        const tool   = doc.docType === 'pdf' ? 'pdf' : 'image';
        const preset = doc.docType === 'photo'      ? 'photo'
                     : doc.docType === 'signature'  ? 'sign'
                     : doc.docType === 'thumbprint' ? 'thumb'
                     : null;
        const params = new URLSearchParams({ tool });
        if (doc.portal) params.set('portal', doc.portal);
        if (preset)     params.set('preset', preset);
        if (doc.w)      params.set('w', doc.w);
        if (doc.h)      params.set('h', doc.h);
        if (doc.maxKb)  params.set('kb', doc.maxKb);
        if (doc.format) params.set('fmt', doc.format);
        return `${WEBSITE_URL}/tools.html?${params.toString()}`;
    }

    function renderDocuments(docs) {
        const docList    = document.getElementById('docList');
        const docSection = document.getElementById('docSection');
        const icons = { photo:'📷', signature:'✍️', thumbprint:'👆', pdf:'📄', document:'📎' };

        docList.innerHTML = docs.map((doc, i) => {
            const icon  = icons[doc.docType] || '📎';
            const parts = [];
            if (doc.w && doc.h) parts.push(`${doc.w}×${doc.h}px`);
            if (doc.maxKb)      parts.push(`≤${doc.maxKb}KB`);
            if (doc.format)     parts.push(doc.format.toUpperCase());
            const meta = parts.length ? parts.join(' · ') : doc.docType;
            const url  = buildToolUrl(doc);
            return `<div class="doc-item">
                <span class="doc-item-label" title="${doc.label}">${icon} ${doc.label}</span>
                <span class="doc-item-meta">${meta}</span>
                <button class="btn-tool" data-url="${url}" data-idx="${i}">Open Free Tool →</button>
            </div>`;
        }).join('');

        docList.querySelectorAll('.btn-tool').forEach(btn => {
            btn.addEventListener('click', () => {
                chrome.tabs.create({ url: btn.dataset.url });
            });
        });

        docSection.style.display = 'block';
    }

    const scanAndRenderDocuments = async () => {
        try {
            const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
            const response = await chrome.tabs.sendMessage(tab.id, { action: 'scanDocuments' });
            if (response?.success && response.documents.length > 0) {
                renderDocuments(response.documents);
            }
        } catch (_) { /* chrome:// page or content script not ready — skip silently */ }
    };

    // ── LOAD CUSTOMER ──────────────────────────────────────────────────────
    loadDataBtn.addEventListener('click', async () => {
        const customerId = customerIdInput.value.trim();
        if (!customerId) { showStatus('Please enter Customer ID.', 'danger'); return; }
        if (!customerId.startsWith('CUS-')) { showStatus('Customer ID must start with CUS-', 'danger'); return; }

        fillFormBtn.disabled = true;
        updatePreview(null);

        const result = await fetchCustomerProfileByCustomerId(customerId);
        if (result.success) {
            loadedCustomerData = result.data;
            await chrome.storage.local.set({ customerData: loadedCustomerData });
            updatePreview(loadedCustomerData);
            showStatus('✅ Ready to fill!', 'success');
        } else {
            showStatus(result.message, 'danger');
            loadedCustomerData = null;
        }
    });

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

        currentUserId = await getCurrentUserId();
        if (!currentUserId) {
            showStatus('❌ Not logged in! Open ai-workflows.cloud, log in, then try again.', 'danger');
            setTimeout(() => chrome.tabs.create({ url: `${WEBSITE_URL}/login.html` }), 1500);
            return;
        }

        const info = await getWalletInfo(currentUserId);
        if (info && info.walletBalance < 0.02) {
            showStatus('❌ Wallet empty! Add money to continue.', 'danger');
            setTimeout(() => chrome.tabs.create({ url: `${WEBSITE_URL}/recharge.html` }), 1500);
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
            const deduct   = await deductWallet(currentUserId, fillResult.filledCount, tab);
            const newBal   = deduct?.walletBalance ?? null;
            const deducted = deduct?.deducted      ?? null;
            const costStr  = deducted !== null
                ? `₹${parseFloat(deducted).toFixed(2)} (${fillResult.filledCount} fields × 2p)` : '';
            const balStr   = newBal !== null
                ? ` | Wallet: ₹${parseFloat(newBal).toFixed(2)}` : '';
            showStatus(`🎉 Done! ${fillResult.filledCount} fields filled. ${costStr} deducted.${balStr}`, 'success');

            if (newBal !== null) {
                document.getElementById('walletAmt').textContent = `💳 ₹${parseFloat(newBal).toFixed(2)}`;
                document.getElementById('walletAmt').style.color = newBal < 0.10 ? '#f44336' : '#8bc34a';
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
            if (confirm('Clear saved customer data from this browser?')) {
                await new Promise(r => chrome.storage.local.remove(['customerData'], r));
                loadedCustomerData = null;
                updatePreview(null);
                customerIdInput.value = '';
                showStatus('🗑️ Local data cleared.', 'success');
            }
        });
    }

    // ── INIT — restore last customer data + wallet bar + scan documents ──
    (async () => {
        await refreshWalletBar();
        chrome.storage.local.get(['customerData'], (result) => {
            if (result.customerData) {
                loadedCustomerData = result.customerData;
                updatePreview(loadedCustomerData);
            }
        });
        await scanAndRenderDocuments();
    })();
});
