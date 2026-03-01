/**
 * Tadarus - API Client Module
 * Handles all server communication with offline fallback
 */

const API_BASE = 'api.php';

/**
 * GET request to API
 */
async function apiGet(action, params = {}) {
    const query = new URLSearchParams({ action, ...params }).toString();
    const url = `${API_BASE}?${query}`;
    
    try {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        
        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: 'خطأ في الخادم' }));
            throw new Error(err.error || `HTTP ${res.status}`);
        }
        
        const data = await res.json();
        
        // Cache response for offline use
        cacheResponse(action, params, data);
        
        return data;
    } catch (err) {
        // Try cached response
        const cached = getCachedResponse(action, params);
        if (cached) return cached;
        throw err;
    }
}

/**
 * POST request to API
 */
async function apiPost(action, body = {}) {
    const url = `${API_BASE}?action=${action}`;
    
    try {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(body)
        });
        
        const data = await res.json();
        return data;
    } catch (err) {
        // Queue for offline sync
        if (!navigator.onLine) {
            queueOfflineAction(action, body);
            return { queued: true, message: 'سيتم المزامنة لاحقاً' };
        }
        throw err;
    }
}

/**
 * Cache API response in localStorage
 */
function cacheResponse(action, params, data) {
    try {
        const key = `tadarus_cache_${action}_${JSON.stringify(params)}`;
        const entry = {
            data: data,
            timestamp: Date.now()
        };
        localStorage.setItem(key, JSON.stringify(entry));
    } catch(e) {
        // localStorage full - clear old entries
        clearOldCache();
    }
}

/**
 * Get cached API response
 */
function getCachedResponse(action, params) {
    try {
        const key = `tadarus_cache_${action}_${JSON.stringify(params)}`;
        const entry = JSON.parse(localStorage.getItem(key));
        if (entry && (Date.now() - entry.timestamp) < 3600000) { // 1 hour
            return entry.data;
        }
    } catch(e) {}
    return null;
}

/**
 * Queue action for offline sync
 */
function queueOfflineAction(action, body) {
    let queue = JSON.parse(localStorage.getItem('tadarus_offline_queue') || '[]');
    queue.push({
        action,
        data: body,
        timestamp: Date.now()
    });
    localStorage.setItem('tadarus_offline_queue', JSON.stringify(queue));
}

/**
 * Process offline queue when back online
 */
async function processOfflineQueue() {
    let queue = JSON.parse(localStorage.getItem('tadarus_offline_queue') || '[]');
    if (!queue.length) return;
    
    const failed = [];
    for (const item of queue) {
        try {
            await apiPost(item.action, item.data);
        } catch(e) {
            failed.push(item);
        }
    }
    
    localStorage.setItem('tadarus_offline_queue', JSON.stringify(failed));
    
    if (failed.length === 0 && queue.length > 0) {
        showToast('تمت مزامنة جميع العمليات المعلقة ✅', 'success');
    }
}

/**
 * Clear old cache entries
 */
function clearOldCache() {
    const keys = [];
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key.startsWith('tadarus_cache_')) {
            keys.push(key);
        }
    }
    // Remove oldest half
    keys.sort();
    keys.slice(0, Math.ceil(keys.length / 2)).forEach(k => localStorage.removeItem(k));
}

// Auto-sync when coming back online
window.addEventListener('online', () => {
    processOfflineQueue();
});
