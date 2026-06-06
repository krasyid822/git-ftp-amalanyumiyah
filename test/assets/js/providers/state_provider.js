// APP CONFIG READ
const config = window.APP_CONFIG || {};

export const daftarAmalanStructure = config.daftarAmalanStructure || {};
export const rawatibDetailsKeys = config.rawatibDetailsKeys || [];
export const prayerKeys = config.prayerKeys || [];
export const displayName = config.displayName || 'Rasyid Kurniawan';

// Reactive App States
export let semuaDataAmalan = config.semuaDataAmalan || {};
export let localChanges = {};
export let offlineSavedChanges = JSON.parse(localStorage.getItem('offlineSavedChanges') || '{}');
export let ayyamulBidhInfo = config.ayyamulBidhInfo || {};

// Clean up any legacy unsaved drafts from localStorage
localStorage.removeItem('localChanges');

// Cache management: Load from offline cache if offline, otherwise overwrite cache with fresh server data
if (!navigator.onLine) {
    const cached = localStorage.getItem('cachedDataAmalan');
    if (cached) {
        try {
            semuaDataAmalan = JSON.parse(cached);
            console.log('Loaded data from offline cache');
        } catch (e) {
            console.error('Failed to parse cached data:', e);
        }
    }
} else {
    localStorage.setItem('cachedDataAmalan', JSON.stringify(semuaDataAmalan));
}

// Merge offlineSavedChanges into semuaDataAmalan local cache on load
for (const dateStr in offlineSavedChanges) {
    const bulan = dateStr.substring(0, 7);
    const day = parseInt(dateStr.substring(8, 10));
    if (!semuaDataAmalan[bulan]) semuaDataAmalan[bulan] = {};
    for (const key in offlineSavedChanges[dateStr]) {
        if (!semuaDataAmalan[bulan][key]) semuaDataAmalan[bulan][key] = {};
        semuaDataAmalan[bulan][key][day] = offlineSavedChanges[dateStr][key];
    }
}

export function updateSemuaDataAmalan(newData) {
    semuaDataAmalan = newData;
    saveDataToLocalCache();
}

export function saveDataToLocalCache() {
    localStorage.setItem('cachedDataAmalan', JSON.stringify(semuaDataAmalan));
}

export function setAyyamulBidhInfo(newInfo) {
    ayyamulBidhInfo = newInfo;
}

export function clearOfflineSavedChanges() {
    offlineSavedChanges = {};
    localStorage.removeItem('offlineSavedChanges');
}

export function setOfflineSavedChanges(changes) {
    offlineSavedChanges = changes;
    localStorage.setItem('offlineSavedChanges', JSON.stringify(offlineSavedChanges));
}

export function clearLocalChanges(dateStr) {
    if (dateStr) {
        delete localChanges[dateStr];
    } else {
        localChanges = {};
    }
    localStorage.setItem('localChanges', JSON.stringify(localChanges));
    updateSyncStatus();
}

export function setLocalChange(dateStr, key, value) {
    if (!localChanges[dateStr]) {
        localChanges[dateStr] = {};
    }
    localChanges[dateStr][key] = value;
    localStorage.setItem('localChanges', JSON.stringify(localChanges));
    updateSyncStatus();
}

export function removeLocalChange(dateStr, key) {
    if (localChanges[dateStr]) {
        delete localChanges[dateStr][key];
        if (Object.keys(localChanges[dateStr]).length === 0) {
            delete localChanges[dateStr];
        }
    }
    localStorage.setItem('localChanges', JSON.stringify(localChanges));
    updateSyncStatus();
}

// Toast logic
let toastTimeout;
export function showToast(message, type = 'success') {
    const toast = document.getElementById('toast-notification');
    if (!toast) return;
    clearTimeout(toastTimeout);
    toast.textContent = message;
    toast.className = ``;
    toast.classList.add(type); // 'success' or 'error'
    
    // Add icon
    const icon = type === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-xmark"></i>';
    toast.innerHTML = `${icon} ${message}`;

    toast.classList.add('show');
    toastTimeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Sync Status logic
export function updateSyncStatus() {
    const syncText = document.getElementById('sync-status-text');
    const syncContainer = document.getElementById('sync-status-container');
    if (!syncText) return;
    
    const changeCount = Object.keys(localChanges).reduce((acc, date) => {
        return acc + Object.keys(localChanges[date]).length;
    }, 0);

    const offlineCount = Object.keys(offlineSavedChanges).reduce((acc, date) => {
        return acc + Object.keys(offlineSavedChanges[date]).length;
    }, 0);

    if (changeCount > 0) {
        syncText.textContent = `Ada ${changeCount} perubahan belum disimpan (lokal)`;
        if (syncContainer) syncContainer.style.color = '#F57C00'; // Orange
        document.body.classList.add('show-floating-save');
        const textEl = document.querySelector('#floating-save-area p');
        if (textEl) {
            textEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="color: #FFB300; animation: warningPulse 1.5s ease-in-out infinite;"></i> Ada ${changeCount} perubahan belum disimpan`;
        }
    } else {
        document.body.classList.remove('show-floating-save');
        if (offlineCount > 0) {
            if (navigator.onLine) {
                syncText.textContent = 'Menyinkronkan data offline...';
                if (syncContainer) syncContainer.style.color = '#F57C00';
                import('../services/sync_service.js').then(m => m.syncOfflineChanges());
            } else {
                syncText.textContent = `Mode Offline (${offlineCount} data tersimpan di lokal - Belum Sinkron)`;
                if (syncContainer) syncContainer.style.color = '#5A5A5A'; // Grey
            }
        } else {
            if (navigator.onLine) {
                syncText.textContent = 'Semua data sinkron dengan server';
                if (syncContainer) syncContainer.style.color = '#006A6A'; // Teal
            } else {
                syncText.textContent = 'Mode Offline (Semua data sinkron / tersimpan di lokal)';
                if (syncContainer) syncContainer.style.color = '#5A5A5A'; // Grey
            }
        }
    }
}
