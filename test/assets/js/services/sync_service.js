import { 
    offlineSavedChanges, 
    updateSemuaDataAmalan, 
    clearOfflineSavedChanges, 
    updateSyncStatus, 
    showToast,
    setAyyamulBidhInfo
} from '../providers/state_provider.js';
import { updateFormForDate } from '../components/table_view.js';

export async function syncOfflineChanges() {
    const changeCount = Object.keys(offlineSavedChanges).reduce((acc, date) => {
        return acc + Object.keys(offlineSavedChanges[date]).length;
    }, 0);
    if (changeCount === 0 || !navigator.onLine) return;
    
    const syncText = document.getElementById('sync-status-text');
    if (syncText) syncText.textContent = 'Menyinkronkan data offline ke server...';
    
    const formData = new FormData();
    formData.append('is_ajax', 'save_multiple_cells');
    formData.append('changes', JSON.stringify(offlineSavedChanges));
    
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.status === 'success') {
            showToast('Data offline berhasil disinkronkan ke server!', 'success');
            updateSemuaDataAmalan(data.updated_data);
            clearOfflineSavedChanges();
            
            const tanggalInput = document.getElementById('tanggal');
            if (tanggalInput) {
                updateFormForDate._skipScroll = true;
                updateFormForDate(tanggalInput.value);
            }
            updateSyncStatus();
        }
    } catch (e) {
        console.error('Failed to sync offline changes:', e);
        updateSyncStatus();
    }
}

export async function fetchAyyamulBidh(tanggalStr) {
    const formData = new FormData();
    formData.append('is_ajax', 'get_ayyamul_bidh');
    formData.append('tanggal', tanggalStr);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        setAyyamulBidhInfo(data);
    } catch (error) {
        console.error('Gagal mengambil data Ayyamul Bidh:', error);
        setAyyamulBidhInfo({ dates: [], title: 'Info Puasa', dates_title: 'Gagal memuat jadwal' });
    }
}
