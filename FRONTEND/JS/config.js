/* ── KAPITBISIG PRODUCTION CONFIGURATION ──
 *
 * This file is loaded FIRST in every HTML page.
 * It sets window.KB_API_BASE so all API calls go to the correct server.
 *
 * Domain : kapitbisig.online
 * API URL : https://kapitbisig.online:5001
 *   (The Node.js backend runs on port 5001 on the same Hostinger server.
 *    If you configure a reverse proxy later to expose /api on port 443,
 *    change PRODUCTION_API_URL to 'https://kapitbisig.online/api'.)
 * ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var hostname = window.location.hostname;
  var isLocal  = hostname === 'localhost' || hostname === '127.0.0.1';

  var PRODUCTION_API_URL = 'https://kapitbisig.online:5001';

  window.KB_API_BASE = isLocal ? 'http://localhost:5001' : PRODUCTION_API_URL;
})();
