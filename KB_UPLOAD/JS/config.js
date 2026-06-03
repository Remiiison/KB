/* ── KAPITBISIG PRODUCTION CONFIGURATION ──
 *
 * This file is loaded FIRST in every HTML page.
 * It sets window.KB_API_BASE so all API calls go to the correct server.
 *
 * Domain  : kapitbisig.online
 * API URL : https://kapitbisig.online/api
 *   (The PHP backend lives in public_html/api/ on Hostinger shared hosting.
 *    Apache routes requests through public_html/api/.htaccess → index.php)
 * ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var hostname = window.location.hostname;
  var isLocal  = hostname === 'localhost' || hostname === '127.0.0.1';

  var PRODUCTION_API_URL = 'https://kapitbisig.online/api';

  window.KB_API_BASE = isLocal ? 'http://localhost:5001' : PRODUCTION_API_URL;
})();
