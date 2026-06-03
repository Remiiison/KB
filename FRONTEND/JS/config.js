/* ── KAPITBISIG PRODUCTION CONFIGURATION ──
 *
 * This file is loaded FIRST in every HTML page.
 * It sets window.KB_API_BASE so api.js, Common.js, SignIn.js, and Signup.js
 * all point to the correct backend — in dev or production.
 *
 * BEFORE DEPLOYING TO HOSTINGER:
 *   Replace 'https://YOUR-API-DOMAIN-HERE' below with your real API URL.
 *
 *   Examples:
 *     'https://api.kapitbisig.ph'          ← dedicated API subdomain
 *     'https://kapitbisig.ph:5001'         ← same domain, explicit port
 *     'https://kapitbisig.ph/api'          ← reverse-proxied via /api path
 *
 *   After updating, commit the file and redeploy.
 * ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var hostname = window.location.hostname;
  var isLocal  = hostname === 'localhost' || hostname === '127.0.0.1';

  /* ↓↓↓ CHANGE THIS LINE FOR PRODUCTION ↓↓↓ */
  var PRODUCTION_API_URL = 'https://YOUR-API-DOMAIN-HERE';
  /* ↑↑↑ CHANGE THIS LINE FOR PRODUCTION ↑↑↑ */

  window.KB_API_BASE = isLocal ? 'http://localhost:5001' : PRODUCTION_API_URL;
})();
