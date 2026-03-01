/**
 * Tadarus - Service Worker
 * Cache-first for static assets, Network-first for API
 */

const CACHE_NAME = 'tadarus-v1';
const STATIC_ASSETS = [
    './',
    'index.html',
    'admin.html',
    'student.html',
    'leaderboard.html',
    'css/style.css',
    'js/api.js',
    'js/app.js',
    'js/sync.js',
    'manifest.json',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css',
    'https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap'
];

// Install - Cache static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate - Clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then(keys => {
                return Promise.all(
                    keys.filter(key => key !== CACHE_NAME)
                        .map(key => caches.delete(key))
                );
            })
            .then(() => self.clients.claim())
    );
});

// Fetch - Strategy based on request type
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // API calls: Network-first with cache fallback
    if (url.pathname.includes('api.php')) {
        event.respondWith(networkFirst(event.request));
        return;
    }

    // Static assets: Cache-first with network fallback
    event.respondWith(cacheFirst(event.request));
});

/**
 * Cache-first strategy
 */
async function cacheFirst(request) {
    try {
        const cached = await caches.match(request);
        if (cached) return cached;

        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        const cached = await caches.match(request);
        if (cached) return cached;

        // Return offline fallback for navigation requests
        if (request.mode === 'navigate') {
            return caches.match('index.html');
        }

        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

/**
 * Network-first strategy (for API)
 */
async function networkFirst(request) {
    try {
        const response = await fetch(request);

        // Cache GET API responses
        if (request.method === 'GET' && response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (e) {
        // Fallback to cache for GET requests
        if (request.method === 'GET') {
            const cached = await caches.match(request);
            if (cached) return cached;
        }

        // Return error JSON
        return new Response(
            JSON.stringify({ error: 'أنت غير متصل بالإنترنت', offline: true }),
            {
                status: 503,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
}

// Background sync
self.addEventListener('sync', (event) => {
    if (event.tag === 'tadarus-sync') {
        event.waitUntil(doBackgroundSync());
    }
});

async function doBackgroundSync() {
    // Notify all clients to process their offline queues
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage({ type: 'sync-ready' });
    });
}

// Push notifications (for future use)
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const data = event.data.json();
    event.waitUntil(
        self.registration.showNotification(data.title || 'تدارس', {
            body: data.body || 'لديك تحديث جديد',
            icon: 'icons/icon-192.png',
            badge: 'icons/icon-192.png',
            dir: 'rtl',
            lang: 'ar',
            vibrate: [200, 100, 200]
        })
    );
});
