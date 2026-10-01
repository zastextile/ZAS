/* Minimal app-shell service worker — exists only to make "Add to Home
   Screen" available and speed up repeat loads of static assets. It
   deliberately does NOT cache any .php page: dashboard/entry/reports show
   live production figures, and caching them could silently show stale
   data on a floor where accuracy matters. Only CSS/JS/icon files are
   cached, using stale-while-revalidate; every dynamic page always goes
   straight to the network. */
var CACHE_NAME = 'zas-production-shell-v1';
var SHELL_ASSETS = [
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png'
];

self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) { return cache.addAll(SHELL_ASSETS); }).catch(function () {})
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE_NAME; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  var isStaticAsset = /\.(css|js|png|jpg|jpeg|svg|ico|woff2?)$/.test(url.pathname);
  if (!isStaticAsset) return; // every .php page: no interception, always network

  e.respondWith(
    caches.match(e.request).then(function (cached) {
      var fetchPromise = fetch(e.request).then(function (res) {
        if (res && res.status === 200) {
          var resClone = res.clone();
          caches.open(CACHE_NAME).then(function (cache) { cache.put(e.request, resClone); });
        }
        return res;
      }).catch(function () { return cached; });
      return cached || fetchPromise;
    })
  );
});
