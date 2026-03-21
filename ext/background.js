// background.js
// This is the service worker for the extension
// All Firebase data fetching logic has been moved to popup.js to comply with MV3 CSP.

// This service worker can be used for other background tasks not requiring external scripts,
// or for event listeners like chrome.runtime.onInstalled.

console.log('Background service worker loaded (simplified for MV3 CSP).');