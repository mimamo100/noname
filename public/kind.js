// A room's colours from the first paint: the kind remembered from the last visit to this room,
// or a neutral one until it loads, rather than The Big Room's orange (app.js sets the real one).
(function () {
  var m = location.pathname.match(/^\/w\/([^/?#]+)/);
  if (!m) return;
  var kind = null;
  try { kind = localStorage.getItem('unsaid:kind:' + m[1]); } catch (e) { /* not saved: neutral */ }
  document.body.dataset.kind = kind || 'loading';
})();
