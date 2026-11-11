/* ZAS Attendance service worker — scope /att/ ONLY.
 *
 * The site already has a service worker at the root (../sw.js) whose rule is
 * "never cache a page, always go to the network", which is right for live
 * production figures and exactly wrong for a gate phone that must open in a
 * dead spot. This one is registered under /att/ and a narrower scope wins, so
 * the two never fight: pages under /att/ get this worker, the rest of the ERP
 * keeps the other, and neither file was changed to make room.
 *
 * WHAT IS CACHED: the app shell — the five files below. Nothing else.
 * WHAT IS NEVER CACHED: anything under /api/ — which includes every employee
 * photo, since photos are served by the API and by nothing else. An attendance answer served
 * from a cache would be a lie with a timestamp on it. API calls are not even
 * intercepted; they go to the network or they fail, and the app queues.
 *
 * RAISE THE VERSION when any shell file changes, or phones keep the old one.
 */
var VERSION = 'zas-att-shell-v3';
var SHELL = ['./', 'index.html', 'app.css?v=3', 'db.js?v=3', 'app.js?v=3', 'manifest.webmanifest'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('zas-att-shell-') === 0 && k !== VERSION; })
      .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.indexOf('/api/') !== -1) return;                 // never the API
  if (url.pathname.indexOf(new URL('./', self.registration.scope).pathname) !== 0) return;   // only our own folder

  // Shell: answer from the cache at once, refresh it in the background.
  e.respondWith(caches.open(VERSION).then(function (cache) {
    return cache.match(e.request, { ignoreSearch: e.request.mode === 'navigate' }).then(function (hit) {
      var net = fetch(e.request).then(function (res) {
        if (res && res.status === 200) cache.put(e.request, res.clone());
        return res;
      }).catch(function () { return hit || cache.match('index.html'); });
      return hit || net;
    });
  }));
});

/* Background Sync is a nudge, not the mechanism: the worker holds no session
   and sends nothing itself. It asks any open copy of the app to run its own
   sync, which is the same code that runs on open, on reconnect and on a timer. */
self.addEventListener('sync', function (e) {
  if (e.tag !== 'att-sync') return;
  e.waitUntil(self.clients.matchAll({ includeUncontrolled: true }).then(function (cs) {
    cs.forEach(function (c) { c.postMessage('att-sync'); });
  }));
});
