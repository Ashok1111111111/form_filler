// content.js — AI Form Agent Bridge (v5.0)
// Thin bridge: discovers form fields, executes fill instructions from server.
// All mapping intelligence lives on the server (form-agent.php) — update anytime instantly.

console.log('🤖 AI Form Agent v5.0 — Server-powered, always up to date!');

function dispatchEvent(element, eventType) {
    if (element) {
        element.dispatchEvent(new Event(eventType, { bubbles: true }));
    }
}

// Generate a unique fingerprint for the current form (sent to server for caching)
function generateFormSignature(fields) {
    const signature = fields
        .slice(0, 20)
        .map(f => {
            const label = f.label.toLowerCase()
                .replace(/[^a-z0-9\s]/g, '')
                .trim()
                .substring(0, 30);
            return `${f.type}:${label}`;
        })
        .join('|');

    let hash = 0;
    for (let i = 0; i < signature.length; i++) {
        const char = signature.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    const formId = Math.abs(hash).toString(36);
    console.log('🔑 Form signature:', formId);
    return formId;
}

// Discover all fillable form fields on the current page with their labels
function discoverFormFields() {
    const fields = [];
    const selectors = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="file"]), select, textarea';
    const elements = document.querySelectorAll(selectors);

    console.log(`🔍 Discovered ${elements.length} form fields`);

    elements.forEach((element, index) => {
        let label = '';

        // 1. Explicit <label for="id">
        if (element.id) {
            const labelEl = document.querySelector(`label[for="${element.id}"]`);
            if (labelEl) label = labelEl.textContent.trim();
        }

        // 2. Walk up to 4 levels looking for a <label> ancestor
        if (!label) {
            let parent = element.parentElement;
            for (let i = 0; i < 4; i++) {
                if (parent) {
                    const labels = parent.querySelectorAll('label');
                    if (labels.length > 0) { label = labels[0].textContent.trim(); break; }
                    parent = parent.parentElement;
                }
            }
        }

        // 3. Table-based forms (ASP.NET / NIC portals) — previous <td> in same <tr>
        if (!label) {
            const td = element.closest('td');
            if (td) {
                const prevTd = td.previousElementSibling;
                if (prevTd) { const txt = prevTd.textContent.trim(); if (txt) label = txt; }
                if (!label) {
                    const tr = td.closest('tr');
                    const prevTr = tr?.previousElementSibling;
                    if (prevTr) { const txt = prevTr.textContent.trim(); if (txt && txt.length < 120) label = txt; }
                }
            }
        }

        // 4. title attribute
        if (!label && element.title) label = element.title.trim();

        // 5. placeholder
        if (!label && element.placeholder) label = element.placeholder.trim();

        fields.push({
            index: index,
            id:    element.id     || `field_${index}`,
            name:  element.name   || '',
            type:  element.type   || element.tagName.toLowerCase(),
            label: label || element.id || element.name || `Field ${index}`,
            element: element   // DOM ref — stripped before sending to server
        });
    });

    return fields;
}

// ── DOCUMENT FIELD DETECTION ─────────────────────────────────────────────────

function getContextText(fileInput) {
    const parts = [];
    if (fileInput.id) {
        const lbl = document.querySelector(`label[for="${fileInput.id}"]`);
        if (lbl) parts.push(lbl.textContent);
    }
    parts.push(fileInput.name || '', fileInput.id || '', fileInput.accept || '');
    let el = fileInput.parentElement;
    for (let i = 0; i < 4 && el; i++) {
        parts.push(el.textContent.replace(/\s+/g, ' ').trim().substring(0, 300));
        el = el.parentElement;
    }
    return parts.join(' ');
}

function detectPortal() {
    const haystack = (document.title + ' ' + location.hostname).toLowerCase();
    const portals = {
        dsssb: /dsssb|dsssbonline/,
        bpsc:  /bpsc|bihar\s*psc|bpsc\.bih/,
        ssc:   /\bssc\b|staff\s*selection/,
        upsc:  /\bupsc\b|civil\s*services/,
        ibps:  /\bibps\b/,
        rrb:   /\brrb\b|railway\s*recruit/,
        sbi:   /\bsbi\b|state\s*bank/,
        nta:   /\bnta\b|jee\b|neet\b/,
        nps:   /\bnps\b|epfo\b/,
        psc:   /\bpsc\b|public\s*service/,
    };
    for (const [key, re] of Object.entries(portals)) {
        if (re.test(haystack)) return key;
    }
    return null;
}

function discoverDocumentFields() {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    if (!fileInputs.length) return [];

    const portal  = detectPortal();
    const results = [];

    fileInputs.forEach((input) => {
        const ctx = getContextText(input).toLowerCase();

        let docType = 'document';
        if (/photo|photograph|passport\s*size|\bpic\b|profile\s*pic/.test(ctx))   docType = 'photo';
        else if (/sign|signature|हस्ताक्षर/.test(ctx))                             docType = 'signature';
        else if (/thumb|thumbprint|fingerprint|अंगूठा/.test(ctx))                  docType = 'thumbprint';
        else if (/certificate|marksheet|\.pdf|pdf\s*only|admit\s*card/.test(ctx))  docType = 'pdf';

        const dimMatch = ctx.match(/(\d{2,4})\s*[x×]\s*(\d{2,4})/i);
        const w = dimMatch ? parseInt(dimMatch[1]) : null;
        const h = dimMatch ? parseInt(dimMatch[2]) : null;

        const kbMatch = ctx.match(/max\s*(\d+)\s*kb/i) || ctx.match(/(\d+)\s*kb/i);
        const maxKb   = kbMatch ? parseInt(kbMatch[1]) : null;

        let format = null;
        if (/\.jpg|jpeg/.test(ctx))  format = 'jpeg';
        else if (/\.png/.test(ctx))  format = 'png';
        else if (/\.pdf/.test(ctx))  format = 'pdf';

        let label = '';
        if (input.id) { const lbl = document.querySelector(`label[for="${input.id}"]`); if (lbl) label = lbl.textContent.trim(); }
        if (!label && input.name) label = input.name.replace(/[_-]/g, ' ');
        if (!label) label = docType.charAt(0).toUpperCase() + docType.slice(1) + ' Upload';
        label = label.charAt(0).toUpperCase() + label.slice(1);

        results.push({ label, docType, portal, w, h, maxKb, format });
    });

    return results;
}

// ── VALUE FORMATTING ──────────────────────────────────────────────────────────

function formatValue(element, value) {
    if (!value) return value;
    let processed = String(value);

    if (element.type === 'number') {
        processed = processed.replace(/[^0-9.-]/g, '');
        const num = parseFloat(processed);
        return isNaN(num) ? null : num;
    }

    if (element.type === 'date') {
        const ddmmyyyy = processed.match(/^(\d{2})[./-](\d{2})[./-](\d{4})$/);
        if (ddmmyyyy) return `${ddmmyyyy[3]}-${ddmmyyyy[2]}-${ddmmyyyy[1]}`;
        const yyyymmdd = processed.match(/^(\d{4})[./-](\d{2})[./-](\d{2})$/);
        if (yyyymmdd) return processed;
    }

    // Text inputs expecting a date string (e.g. DSSSB, NIC portals with DD/MM/YYYY)
    if (element.type === 'text' || element.type === '') {
        const hint = ((element.placeholder || '') + ' ' + (element.title || '') + ' ' +
                      (element.name || '') + ' ' + (element.id || '')).toLowerCase();
        const isDateHint = /dd[\/\-]mm[\/\-]yyyy|date|dob|birth|txtdob|dateofbirth|dt_birth/.test(hint) ||
                           element.maxLength === 10;
        if (isDateHint) {
            // YYYY-MM-DD → DD/MM/YYYY
            const isoMatch = processed.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (isoMatch) return `${isoMatch[3]}/${isoMatch[2]}/${isoMatch[1]}`;
            // DD-MM-YYYY → DD/MM/YYYY (sites like DSSSB only accept '/')
            const dashMatch = processed.match(/^(\d{2})-(\d{2})-(\d{4})$/);
            if (dashMatch) return `${dashMatch[1]}/${dashMatch[2]}/${dashMatch[3]}`;
            // Already DD/MM/YYYY — return as-is
            if (/^\d{2}\/\d{2}\/\d{4}$/.test(processed)) return processed;
        }
    }

    return processed;
}

// ── INDIAN STATE ALIASES ──────────────────────────────────────────────────────
const STATE_ALIASES = {
  'ap':'andhra pradesh','andhra':'andhra pradesh','ఆంధ్రప్రదేశ్':'andhra pradesh',
  'ar':'arunachal pradesh','arunachal':'arunachal pradesh',
  'as':'assam','অসম':'assam',
  'br':'bihar','बिहार':'bihar',
  'cg':'chhattisgarh','chattisgarh':'chhattisgarh','छत्तीसगढ़':'chhattisgarh','ch':'chhattisgarh',
  'dl':'delhi','new delhi':'delhi','दिल्ली':'delhi','nct of delhi':'delhi','nct delhi':'delhi',
  'ga':'goa',
  'gj':'gujarat','gu':'gujarat','ગુજરાત':'gujarat',
  'hr':'haryana','हरियाणा':'haryana',
  'hp':'himachal pradesh','himachal':'himachal pradesh','हिमाचल प्रदेश':'himachal pradesh',
  'jh':'jharkhand','झारखंड':'jharkhand',
  'ka':'karnataka','ಕರ್ನಾಟಕ':'karnataka',
  'kl':'kerala','ke':'kerala','കേരളം':'kerala',
  'mp':'madhya pradesh','म.प्र.':'madhya pradesh','मध्य प्रदेश':'madhya pradesh',
  'mh':'maharashtra','महाराष्ट्र':'maharashtra',
  'mn':'manipur','মণিপুর':'manipur',
  'ml':'meghalaya','mz':'mizoram','nl':'nagaland',
  'or':'odisha','od':'odisha','orissa':'odisha','ओडिशा':'odisha','ଓଡ଼ିଶା':'odisha',
  'pb':'punjab','pj':'punjab','ਪੰਜਾਬ':'punjab',
  'rj':'rajasthan','राजस्थान':'rajasthan',
  'sk':'sikkim',
  'tn':'tamil nadu','tamilnadu':'tamil nadu','தமிழ்நாடு':'tamil nadu',
  'tg':'telangana','ts':'telangana','తెలంగాణ':'telangana',
  'tr':'tripura',
  'up':'uttar pradesh','u.p.':'uttar pradesh','उत्तर प्रदेश':'uttar pradesh',
  'uk':'uttarakhand','ua':'uttarakhand','uttaranchal':'uttarakhand','उत्तराखंड':'uttarakhand',
  'wb':'west bengal','পশ্চিমবঙ্গ':'west bengal',
  'jk':'jammu and kashmir','j&k':'jammu and kashmir','jammu kashmir':'jammu and kashmir','jammu & kashmir':'jammu and kashmir',
  'la':'ladakh','an':'andaman and nicobar','andaman':'andaman and nicobar',
  'dd':'daman and diu','daman':'daman and diu',
  'dn':'dadra and nagar haveli','dadra':'dadra and nagar haveli',
  'ld':'lakshadweep',
  'py':'puducherry','pondicherry':'puducherry','पुदुच्चेरी':'puducherry',
};

function resolveStateAlias(val) {
    const key = val.toLowerCase().trim();
    return STATE_ALIASES[key] || key;
}

// Common value aliases for dropdowns — maps customer data values to form option text
const VALUE_ALIASES = {
  // Division / Grade
  '1st':'first division','1st division':'first division','first':'first division',
  '2nd':'second division','2nd division':'second division','second':'second division',
  '3rd':'third division','3rd division':'third division','third':'third division',
  'dist':'distinction','distiction':'distinction',
  // Yes / No
  'yes':'yes','no':'no','y':'yes','n':'no',
  // Gender
  'male':'male','female':'female','m':'male','f':'female','trans':'transgender','tg':'transgender',
  // Marital
  'unmarried':'unmarried','single':'unmarried','married':'married','divorced':'divorced','widowed':'widowed','widow':'widowed',
  // Category
  'gen':'general','general':'general','obc':'obc','obc-ncl':'obc-ncl','obc ncl':'obc-ncl',
  'sc':'sc','st':'st','ews':'ews',
  // Religion
  'hindu':'hindu','muslim':'muslim','islam':'muslim','christian':'christian','sikh':'sikh',
  'buddhist':'buddhist','jain':'jain','parsi':'parsi','others':'others','other':'others',
  // Nationality
  'indian':'indian','india':'indian',
};

function resolveValueAlias(val) {
    const key = val.toLowerCase().trim();
    return VALUE_ALIASES[key] || key;
}

// ── FILL FUNCTIONS ────────────────────────────────────────────────────────────

function applyOption(element, option) {
    element.value = option.value;
    dispatchEvent(element, 'change');
    element.style.backgroundColor = '#c3f0ca';
    setTimeout(() => element.style.backgroundColor = '', 2000);
}

function fillInput(element, value) {
    const formatted = formatValue(element, value);
    if (!formatted || element.value === String(formatted)) return false;
    try {
        element.focus();
        element.value = formatted;
        dispatchEvent(element, 'input');
        dispatchEvent(element, 'change');
        dispatchEvent(element, 'blur');
        element.style.backgroundColor = '#c3f0ca';
        setTimeout(() => element.style.backgroundColor = '', 2000);
        return true;
    } catch (e) { return false; }
}

function fillSelect(element, value) {
    const valueLower    = String(value).toLowerCase().trim();
    if (!valueLower) return false;
    const valueResolved = resolveValueAlias(resolveStateAlias(valueLower));
    const validOptions  = Array.from(element.options).filter(o => o.value !== '' && o.value !== '0');

    function tryMatch(search) {
        for (const opt of validOptions) {
            const t = opt.text.toLowerCase().trim();
            const v = opt.value.toLowerCase().trim();
            if (t === search || v === search) { applyOption(element, opt); return true; }
        }
        for (const opt of validOptions) {
            const t = opt.text.toLowerCase().trim();
            const v = opt.value.toLowerCase().trim();
            if (t.includes(search) || search.includes(t) || v.includes(search)) {
                applyOption(element, opt); return true;
            }
        }
        const fw = search.split(/[\s\/\-]/)[0];
        if (fw.length >= 2) {
            for (const opt of validOptions) {
                const t = opt.text.toLowerCase().trim();
                const v = opt.value.toLowerCase().trim();
                if (t.startsWith(fw) || v.startsWith(fw)) { applyOption(element, opt); return true; }
            }
        }
        return false;
    }

    return tryMatch(valueLower) || (valueResolved !== valueLower && tryMatch(valueResolved));
}

function fillRadio(element, value) {
    if (!element.name) return false;
    const radios     = document.querySelectorAll(`input[name="${element.name}"]`);
    const valueLower = String(value).toLowerCase();
    for (const radio of radios) {
        const radioVal = String(radio.value).toLowerCase();
        const label    = radio.nextElementSibling?.textContent?.toLowerCase() || '';
        if (radioVal.includes(valueLower) || valueLower.includes(radioVal) ||
            label.includes(valueLower)    || valueLower.charAt(0) === radioVal.charAt(0)) {
            radio.checked = true;
            dispatchEvent(radio, 'change');
            return true;
        }
    }
    return false;
}

function fillField(field, value) {
    const element = field.element;
    if (field.type === 'select' || field.type === 'select-one' || element.tagName === 'SELECT') {
        return fillSelect(element, value);
    } else if (field.type === 'radio') {
        return fillRadio(element, value);
    } else {
        return fillInput(element, value);
    }
}

// ── MESSAGE LISTENER ──────────────────────────────────────────────────────────

chrome.runtime.onMessage.addListener((request, _sender, sendResponse) => {

    // STEP 1 — Scan fields and return them to popup
    // popup.js calls this first, then posts the fields to form-agent.php
    if (request.action === 'scanFields') {
        try {
            const fields = discoverFormFields();
            if (!fields.length) {
                sendResponse({ success: false, message: 'No form fields found on this page.' });
                return true;
            }
            const formSignature = generateFormSignature(fields);
            // Strip .element (DOM node — not serialisable over chrome.tabs.sendMessage)
            const serialisableFields = fields.map(({ element, ...rest }) => rest);
            sendResponse({ success: true, fields: serialisableFields, formSignature });
        } catch(e) {
            sendResponse({ success: false, message: e.message });
        }
        return true;
    }

    // STEP 2 — Execute mapping returned by server agent
    // popup.js receives {mapping: {"0":"firstName", ...}} from form-agent.php and sends it here
    if (request.action === 'executeMapping') {
        (async () => {
            const { mapping, customerData } = request;
            const delay = ms => new Promise(r => setTimeout(r, ms));
            const fields = discoverFormFields(); // re-discover for fresh .element DOM refs
            let filledCount = 0;
            const pendingSelects = [];

            // Pass 1 — fill all non-select fields immediately; queue failed selects
            for (const field of fields) {
                const dataKey = mapping[String(field.index)];
                if (!dataKey || !customerData[dataKey]) continue;
                const value    = customerData[dataKey];
                const isSelect = field.type === 'select' || field.type === 'select-one'
                              || field.element.tagName === 'SELECT';
                console.log(`✅ "${field.label}" ← ${dataKey} = "${String(value).substring(0, 40)}"`);
                if (isSelect) {
                    if (fillField(field, value)) { filledCount++; await delay(50); }
                    else pendingSelects.push({ field, value });
                } else {
                    if (fillField(field, value)) { filledCount++; await delay(50); }
                }
            }

            // Pass 2 — retry failed selects after 600ms (AJAX-loaded dropdown options)
            if (pendingSelects.length > 0) {
                await delay(600);
                for (const { field, value } of pendingSelects) {
                    if (fillField(field, value)) filledCount++;
                    else console.warn(`⚠️ Could not fill dropdown "${field.label}" with "${value}"`);
                }
            }

            console.log(`🎉 Done! Filled: ${filledCount}`);
            sendResponse({ success: true, filledCount });
        })();
        return true; // keep message channel open for async response
    }

    // Scan page for document upload requirements (no credits consumed)
    if (request.action === 'scanDocuments') {
        try {
            const documents = discoverDocumentFields();
            sendResponse({ success: true, documents });
        } catch(e) {
            sendResponse({ success: false, documents: [] });
        }
        return true;
    }
});

// ── WEBSITE ↔ EXTENSION USER SYNC ────────────────────────────────────────────
// Listens for login event from ai-workflows.cloud
// Website sends: window.postMessage({ type: 'AIFF_SET_USER', userId }, '*')
window.addEventListener('message', (event) => {
    if (event.origin !== 'https://ai-workflows.cloud') return;
    const msg = event.data;
    if (msg && msg.type === 'AIFF_SET_USER' && msg.userId) {
        chrome.storage.local.set({ userId: msg.userId }, () => {
            console.log('✅ AI Form Agent: User synced —', msg.userId);
        });
    }
});
