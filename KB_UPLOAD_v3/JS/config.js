/* ── KAPITBISIG PRODUCTION CONFIGURATION ── */
(function () {
  'use strict';

  var hostname = window.location.hostname;
  var isLocal  = hostname === 'localhost' || hostname === '127.0.0.1';

  /*
   * In production the PHP API lives at /api/ on the same server,
   * so we use a relative path — works on the Hostinger preview URL
   * (kapitbisig-online-598946.hostingersite.com) AND on the real
   * domain (kapitbisig.online) without any changes.
   */
  window.KB_API_BASE = isLocal ? 'http://localhost:5001' : '/api';
})();
