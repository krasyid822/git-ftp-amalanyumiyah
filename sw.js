// FILE: sw.js
const CACHE_NAME = 'tracker-amalan-v1';
const ASSETS_TO_CACHE = [
  'https://fonts.googleapis.com/css2?family=Roboto+Flex:opsz,wght@8..144,300;8..144,400;8..144,500;8..144,600;8..144,700&display=swap',
  'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'
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
  // Only handle GET requests. POST requests (e.g. AJAX saves) should bypass the cache.
  if (e.request.method !== 'GET') {
    return;
  }

  e.respondWith(
    fetch(e.request)
      .then((response) => {
        // If valid response, clone and cache it
        if (response.status === 200) {
          const resClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(e.request, resClone);
          });
        }
        return response;
      })
      .catch(() => {
        // If network fails, try cache
        return caches.match(e.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // Normalize paths for matching index pages
          if (e.request.headers.get('accept').includes('text/html')) {
            const url = new URL(e.request.url);
            const path = url.pathname;
            
            // Try matching path directly, or appending index.php if it's a directory path
            const possiblePaths = [
              path,
              path.endsWith('/') ? path + 'index.php' : path + '/index.php',
              path.endsWith('index.php') ? path.substring(0, path.lastIndexOf('index.php')) : path
            ];
            
            return Promise.any(
              possiblePaths.map(p => caches.match(p).then(res => {
                if (res) return res;
                throw new Error('Not found');
              }))
            ).catch(() => {
              return caches.match(e.request) || caches.match('./index.php') || caches.match('./');
            });
          }
        });
      })
  );
});
