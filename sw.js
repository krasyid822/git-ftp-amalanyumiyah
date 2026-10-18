// FILE: sw.js
const CACHE_NAME = 'tracker-amalan-v22';
const ASSETS_TO_CACHE = [
  'https://fonts.googleapis.com/css2?family=Roboto+Flex:opsz,wght@8..144,300;8..144,400;8..144,500;8..144,600;8..144,700&display=swap',
  'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js',
  // Font Poppins untuk ekspor PDF (relatif terhadap /sw.js → /test/assets/fonts/).
  'test/assets/fonts/Poppins-Regular.ttf',
  'test/assets/fonts/Poppins-Bold.ttf'
];

// Install Event
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
    }).then(() => self.skipWaiting())
  );
});

// Activate Event
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch Event
self.addEventListener('fetch', (e) => {
  const req = e.request;

  // Only handle GET requests. POST requests (e.g. AJAX saves) bypass the cache.
  if (req.method !== 'GET') {
    return;
  }

  // Only http/https can be stored by the Cache API. Skip other schemes such as
  // chrome-extension:, data:, blob:, etc. Otherwise cache.put() throws:
  // "Request scheme 'chrome-extension' is unsupported".
  let url;
  try {
    url = new URL(req.url);
  } catch {
    return;
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return;
  }

  e.respondWith(
    fetch(req)
      .then((response) => {
        // Cache only valid, storable responses (skip opaque/partial ones).
        if (response && response.status === 200 && response.type !== 'opaque') {
          const resClone = response.clone();
          caches.open(CACHE_NAME)
            .then((cache) => cache.put(req, resClone))
            .catch(() => { /* ignore: some responses cannot be cached */ });
        }
        return response;
      })
      .catch((networkError) => {
        // Network failed: try the cache.
        return caches.match(req).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }

          const accept = req.headers.get('accept') || '';
          if (!accept.includes('text/html')) {
            throw networkError;
          }

          // Normalize paths for matching index pages.
          const path = url.pathname;
          const possiblePaths = [
            path,
            path.endsWith('/') ? path : path + '/',
            path.endsWith('/') ? path + 'index.php' : path + '/index.php',
            path.endsWith('index.php') ? path.substring(0, path.lastIndexOf('index.php')) : path
          ];

          return Promise.any(
            possiblePaths.map((p) => caches.match(p).then((res) => {
              if (res) return res;
              throw new Error('Not found');
            }))
          ).catch(() => {
            return Promise.all([
              caches.match(req),
              caches.match('./index.php'),
              caches.match('./')
            ]).then((candidates) => {
              const found = candidates.find(Boolean);
              if (found) return found;
              throw networkError;
            });
          });
        });
      })
  );
});
