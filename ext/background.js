// background.js — AI Form Filler service worker
console.log('Background service worker loaded.');

const VERSION_URL = 'https://formfiller.ai-workflows.cloud/ext-version.json';

// Check server version every 30 minutes — auto-reload if updated
async function checkForUpdate() {
    try {
        const res  = await fetch(VERSION_URL + '?t=' + Date.now());
        const data = await res.json();
        const stored = await chrome.storage.local.get(['extVersion']);
        if (stored.extVersion && stored.extVersion !== data.version) {
            console.log('🔄 New version detected — reloading extension...');
            await chrome.storage.local.set({ extVersion: data.version });
            chrome.runtime.reload();
        } else if (!stored.extVersion) {
            await chrome.storage.local.set({ extVersion: data.version });
        }
    } catch (_) {}
}

checkForUpdate();
setInterval(checkForUpdate, 30 * 60 * 1000); // every 30 min