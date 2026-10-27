/* SHWAC admin: start-up. Loaded last, after every page file has registered its routes. */
(function () {
  'use strict';
  if (!location.hash) history.replaceState(null, '', '#/dashboard');
  SH.route();
  // Keep sidebar badges fresh while the panel is open.
  setInterval(() => { if (SH.state.token && document.visibilityState === 'visible') SH.refreshCounts(); }, 120000);
})();
