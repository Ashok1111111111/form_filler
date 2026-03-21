/**
 * AI Form Agent — Automated Test Runner
 * Tests form-agent.php + content.js filling logic on multiple portal types.
 * Run: node tests/run-tests.js
 */

const { chromium } = require('playwright');
const path  = require('path');
const fs    = require('fs');
const http  = require('http');
const CUSTOMER = require('./test-data.js');

const SERVER_URL  = 'https://ai-workflows.cloud';
const FORMS_DIR   = path.join(__dirname, 'forms');
const REPORT_FILE = path.join(__dirname, 'report.html');

// ── TEST SUITES ───────────────────────────────────────────────────────────────
const SUITES = [
  {
    name: 'BPSC / SSC Government Job Form',
    file: 'bpsc-form.html',
    expected: {
      firstName: 'Ashok', lastName: 'kumar', fullName: 'Ashok kumar',
      fatherName: 'Awadhesh Singh', motherName: 'Shankuntala devi',
      emailId: 'ashok@test.com', mobileNumber: '9999988888',
      aadhaarNumber: '123456789012', panNumber: 'ABCDE1234F',
      permDistrict: 'Bhojpur', permState: 'Bihar', permPin: '802201',
      tenthBoard: 'BSEB', tenthYear: '2010',
      gradDegree: 'B.Tech', gradUniv: 'RGPV Bhopal',
      bankAcc: '123456789012345', bankIFSC: 'SBIN0001234',
    },
    shouldNotFill: [],
  },
  {
    name: 'IRCTC / Railway Booking Form',
    file: 'irctc-form.html',
    expected: {
      firstName: 'Ashok', lastName: 'kumar',
      emailId: 'ashok@test.com', mobileNumber: '9999988888',
      aadhaarNumber: '123456789012', panNumber: 'ABCDE1234F',
      permPin: '802201', permDistrict: 'Bhojpur',
    },
    shouldNotFill: ['occupation', 'organization'],
  },
  {
    name: 'PM-KISAN / Kisan / Village Form (Hindi Labels)',
    file: 'kisan-form.html',
    expected: {
      mobileNo: '9999988888',
      aadhaarNo: '123456789012',
      pincodeField: '802201',
      bankAccount: '123456789012345',
      ifscCode: 'SBIN0001234',
      bankNameField: 'SBI',
      casteCertNo: 'CASTE123456',
      incomeCertNo: 'INC123456',
      domicileCertNo: 'DOM123456',
      emailAddress: 'ashok@test.com',
    },
    shouldNotFill: [],
  },
  {
    name: 'Domicile / Caste / Income Certificate Form',
    file: 'domicile-form.html',
    expected: {
      fatherName: 'Awadhesh Singh', motherName: 'Shankuntala devi',
      mobileNumber: '9999988888', emailId: 'ashok@test.com',
      aadhaarNumber: '123456789012',
      permPO: 'Agiaon', permPS: 'Agiaon',
      permDistrict: 'Bhojpur', permState: 'Bihar', permPin: '802201',
      domicileCertNo: 'DOM123456', domicileAuthority: 'Tehsildar, Bhojpur',
      casteCertNo: 'CASTE123456', casteAuthority: 'Tehsildar',
      incomeCertNo: 'INC123456', annualIncome: '87578',
    },
    shouldNotFill: [],
  },
];

// ── CONTENT.JS LOGIC (injected into page) ────────────────────────────────────
const FILL_SCRIPT = fs.readFileSync(
  path.join(__dirname, '..', 'ext', 'content.js'), 'utf8'
);

// ── SERVE TEST FORMS LOCALLY ──────────────────────────────────────────────────
function startLocalServer(port) {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      const filePath = path.join(FORMS_DIR, req.url.replace('/', ''));
      if (fs.existsSync(filePath)) {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(fs.readFileSync(filePath));
      } else {
        res.writeHead(404); res.end('Not found');
      }
    });
    server.listen(port, () => resolve(server));
  });
}

// ── MAIN TEST RUNNER ──────────────────────────────────────────────────────────
async function runTests() {
  console.log('\n🧪 AI Form Agent — Automated Test Suite\n' + '═'.repeat(50));

  const server = await startLocalServer(9977);
  const browser = await chromium.launch({ headless: true });
  const results = [];

  for (const suite of SUITES) {
    console.log(`\n📋 Testing: ${suite.name}`);
    const page = await browser.newPage();

    try {
      await page.goto(`http://localhost:9977/${suite.file}`);

      // Inject content.js fill logic into the page
      await page.addScriptTag({ content: FILL_SCRIPT });

      // Discover form fields (mirrors content.js discoverFormFields)
      const fields = await page.evaluate(() => {
        const elements = document.querySelectorAll(
          'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="file"]), select, textarea'
        );
        return Array.from(elements).map((el, i) => {
          let label = '';
          if (el.id) {
            const lbl = document.querySelector(`label[for="${el.id}"]`);
            if (lbl) label = lbl.textContent.trim();
          }
          if (!label && el.placeholder) label = el.placeholder;
          if (!label) label = el.id || el.name || `Field ${i}`;
          return { index: i, id: el.id || '', name: el.name || '', type: el.type || 'text', label };
        });
      });

      // Call form-agent.php
      const agentResp = await page.evaluate(async ({ fields, customerData, serverUrl }) => {
        const sig = fields.slice(0, 20)
          .map(f => `${f.type}:${f.label.toLowerCase().replace(/[^a-z0-9\s]/g,'').trim().substring(0,30)}`)
          .join('|');
        let hash = 0;
        for (let i = 0; i < sig.length; i++) {
          hash = ((hash << 5) - hash) + sig.charCodeAt(i);
          hash = hash & hash;
        }
        const formSignature = Math.abs(hash).toString(36);

        try {
          const res = await fetch(`${serverUrl}/form-agent.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fields, customerData, formSignature }),
          });
          return await res.json();
        } catch (e) {
          return { success: false, error: e.message };
        }
      }, { fields, customerData: CUSTOMER, serverUrl: SERVER_URL });

      if (!agentResp.success) {
        console.log(`  ❌ Server error: ${agentResp.error}`);
        results.push({ suite: suite.name, error: agentResp.error, fieldResults: [] });
        continue;
      }

      const mapping = agentResp.mapping || {};
      console.log(`  🗺️  Mapped ${Object.keys(mapping).length} fields`);

      // Execute fill
      await page.evaluate(({ mapping, customerData }) => {
        window.__TEST_MAPPING    = mapping;
        window.__TEST_CUSTOMER   = customerData;
      }, { mapping, customerData: CUSTOMER });

      await page.evaluate(() => {
        const elements = document.querySelectorAll(
          'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="file"]), select, textarea'
        );
        Array.from(elements).forEach((el, i) => {
          const dataKey = window.__TEST_MAPPING[String(i)];
          if (!dataKey) return;
          const value = window.__TEST_CUSTOMER[dataKey];
          if (!value) return;
          if (el.tagName === 'SELECT') {
            const opts = Array.from(el.options);
            const match = opts.find(o =>
              o.text.toLowerCase().includes(value.toLowerCase()) ||
              o.value.toLowerCase() === value.toLowerCase()
            );
            if (match) el.value = match.value;
          } else {
            el.value = value;
          }
        });
      });

      // Check results
      const fieldResults = [];
      for (const [fieldName, expectedValue] of Object.entries(suite.expected)) {
        const actual = await page.$eval(
          `[name="${fieldName}"], #${fieldName}`,
          el => el.value
        ).catch(() => null);

        const pass = actual !== null &&
          actual.toLowerCase().includes(expectedValue.toLowerCase());

        console.log(`  ${pass ? '✅' : '❌'} ${fieldName}: expected "${expectedValue}" got "${actual ?? 'NOT FOUND'}"`);
        fieldResults.push({ field: fieldName, expected: expectedValue, actual: actual ?? 'NOT FOUND', pass });
      }

      // Check shouldNotFill
      for (const fieldName of suite.shouldNotFill) {
        const actual = await page.$eval(
          `[name="${fieldName}"], #${fieldName}`,
          el => el.value
        ).catch(() => null);
        const pass = !actual;
        console.log(`  ${pass ? '✅' : '❌'} (should NOT fill) ${fieldName}: "${actual ?? 'empty'}"`);
        fieldResults.push({ field: `[NO-FILL] ${fieldName}`, expected: '(empty)', actual: actual ?? 'empty', pass });
      }

      const passed = fieldResults.filter(r => r.pass).length;
      console.log(`  📊 Score: ${passed}/${fieldResults.length} passed`);
      results.push({ suite: suite.name, fieldResults, mapped: Object.keys(mapping).length });

    } catch (err) {
      console.log(`  ❌ Suite crashed: ${err.message}`);
      results.push({ suite: suite.name, error: err.message, fieldResults: [] });
    }

    await page.close();
  }

  await browser.close();
  server.close();

  generateReport(results);
  console.log(`\n📄 Report saved: ${REPORT_FILE}`);
}

// ── HTML REPORT GENERATOR ─────────────────────────────────────────────────────
function generateReport(results) {
  const totalPass = results.reduce((s, r) => s + r.fieldResults.filter(f => f.pass).length, 0);
  const totalAll  = results.reduce((s, r) => s + r.fieldResults.length, 0);
  const pct = totalAll ? Math.round(100 * totalPass / totalAll) : 0;

  const suiteHTML = results.map(r => {
    if (r.error) return `
      <div class="suite error">
        <h3>❌ ${r.suite}</h3>
        <p class="err-msg">${r.error}</p>
      </div>`;

    const rows = r.fieldResults.map(f => `
      <tr class="${f.pass ? 'pass' : 'fail'}">
        <td>${f.field}</td>
        <td>${f.expected}</td>
        <td>${f.actual}</td>
        <td>${f.pass ? '✅' : '❌'}</td>
      </tr>`).join('');

    const p = r.fieldResults.filter(f => f.pass).length;
    const t = r.fieldResults.length;
    return `
      <div class="suite">
        <h3>${r.suite} <span class="badge ${p===t?'green':'orange'}">${p}/${t}</span>
          <span class="mapped">🗺 ${r.mapped} fields mapped by AI</span></h3>
        <table>
          <thead><tr><th>Field</th><th>Expected</th><th>Got</th><th>Result</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }).join('');

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AI Form Agent — Test Report</title>
<style>
  body{font-family:system-ui,sans-serif;max-width:1100px;margin:40px auto;padding:0 20px;background:#f8fafc}
  h1{color:#1e3a5f;border-bottom:3px solid #3b82f6;padding-bottom:10px}
  .summary{background:#1e3a5f;color:white;border-radius:12px;padding:20px 30px;margin:20px 0;display:flex;gap:40px;align-items:center}
  .big-num{font-size:3rem;font-weight:800;color:#60a5fa}
  .suite{background:white;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin:20px 0;padding:20px}
  .suite h3{margin:0 0 14px;color:#1e3a5f;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .badge{font-size:.8rem;padding:2px 10px;border-radius:20px;font-weight:700}
  .badge.green{background:#d1fae5;color:#065f46}
  .badge.orange{background:#fef3c7;color:#92400e}
  .mapped{font-size:.75rem;color:#6b7280;font-weight:400}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  th{background:#f1f5f9;padding:8px 12px;text-align:left;border-bottom:2px solid #e2e8f0}
  td{padding:7px 12px;border-bottom:1px solid #f1f5f9}
  tr.pass td:first-child{border-left:3px solid #22c55e}
  tr.fail td:first-child{border-left:3px solid #ef4444}
  tr.fail{background:#fff5f5}
  .err-msg{color:#ef4444;background:#fff5f5;padding:10px;border-radius:6px}
  .suite.error{border-left:4px solid #ef4444}
  .ts{font-size:.8rem;color:#94a3b8;margin-top:30px;text-align:center}
</style>
</head>
<body>
<h1>🤖 AI Form Agent — Automated Test Report</h1>
<div class="summary">
  <div><div class="big-num">${pct}%</div><div>Overall Pass Rate</div></div>
  <div><div class="big-num">${totalPass}/${totalAll}</div><div>Fields Correct</div></div>
  <div><div class="big-num">${results.length}</div><div>Forms Tested</div></div>
</div>
${suiteHTML}
<p class="ts">Generated: ${new Date().toLocaleString('en-IN')} · AI Form Agent v5.0</p>
</body>
</html>`;

  fs.writeFileSync(REPORT_FILE, html);
}

runTests().catch(err => {
  console.error('Test runner crashed:', err);
  process.exit(1);
});
