import { 
    semuaDataAmalan, 
    localChanges, 
    offlineSavedChanges, 
    ayyamulBidhInfo, 
    clearLocalChanges, 
    updateSemuaDataAmalan, 
    clearOfflineSavedChanges, 
    updateSyncStatus, 
    showToast,
    displayName
} from './providers/state_provider.js';
import { 
    updateFormForDate, 
    scrollReportToSelectedDate,
    updateTableCellVisually
} from './components/table_view.js';
import { 
    openPopover, 
    closePopover, 
    getCurrentFormValue 
} from './components/popover_editor.js';
import { 
    syncOfflineChanges, 
    fetchAyyamulBidh 
} from './services/sync_service.js';
import { downloadCurrentTableAsPdf } from './services/pdf_generator.js';
import { isSeninKamisJS } from './utils/date_helper.js';
import { initPrayerSliders } from './components/prayer_slider.js';

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Prayer Sliders
    initPrayerSliders();
    // Register Service Worker for offline support at the root scope of the domain
    if ('serviceWorker' in navigator) {
        // Calculate relative path to root dynamically based on current path depth
        const pathname = window.location.pathname;
        // e.g., /sda/rafie/index.php -> segments: ["", "sda", "rafie", "index.php"] -> depth = 3 (needs "../../sw.js")
        // e.g., /test/index.php -> segments: ["", "test", "index.php"] -> depth = 2 (needs "../sw.js")
        const segments = pathname.split('/').filter(Boolean);
        let swPath = 'sw.js';
        let swScope = './';
        if (segments.length > 1) {
            const depth = segments.length - 1;
            swPath = '../'.repeat(depth) + 'sw.js';
            swScope = '../'.repeat(depth);
        }
        
        navigator.serviceWorker.register(swPath, { scope: swScope })
            .then(reg => console.log('Service Worker Registered dynamically:', reg.scope))
            .catch(err => console.error('Service Worker Registration Failed:', err));
    }

    const form = document.getElementById('form-amalan');
    const tanggalInput = document.getElementById('tanggal');
    const prayerKeys = window.APP_CONFIG?.prayerKeys || [];
    
    // Auto-sync offline changes on load
    setTimeout(() => {
        updateSyncStatus();
        if (navigator.onLine) {
            syncOfflineChanges();
        }
    }, 100);

    // Event listener for online/offline status
    window.addEventListener('online', () => {
        updateSyncStatus();
        syncOfflineChanges();
    });
    window.addEventListener('offline', updateSyncStatus);

    // Form Submission with AJAX
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = form.querySelector('.submit-btn');
        const floatingSubmitBtn = document.querySelector('#floating-save-area .submit-btn');
        const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
        const floatingOriginalText = floatingSubmitBtn ? floatingSubmitBtn.innerHTML : '';
        
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
            submitBtn.disabled = true;
        }
        if (floatingSubmitBtn) {
            floatingSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
            floatingSubmitBtn.disabled = true;
        }

        if (navigator.onLine) {
            const mergedChanges = JSON.parse(JSON.stringify(offlineSavedChanges));
            for (const dateStr in localChanges) {
                if (!mergedChanges[dateStr]) mergedChanges[dateStr] = {};
                for (const key in localChanges[dateStr]) {
                    mergedChanges[dateStr][key] = localChanges[dateStr][key];
                }
            }

            const formData = new FormData();
            formData.append('is_ajax', 'save_multiple_cells');
            formData.append('changes', JSON.stringify(mergedChanges));

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    updateSemuaDataAmalan(data.updated_data);
                    
                    clearLocalChanges();
                    clearOfflineSavedChanges();
                    
                    updateFormForDate._skipScroll = true;
                    updateFormForDate(tanggalInput.value);
                    updateSyncStatus();
                } else {
                    showToast(data.message || 'Terjadi kesalahan.', 'error');
                }
            })
            .catch(error => {
                saveDraftLocally();
            })
            .finally(() => {
                resetSaveButtons();
            });
        } else {
            saveDraftLocally();
            resetSaveButtons();
        }

        function saveDraftLocally() {
            for (const dateStr in localChanges) {
                if (!offlineSavedChanges[dateStr]) offlineSavedChanges[dateStr] = {};
                for (const key in localChanges[dateStr]) {
                    offlineSavedChanges[dateStr][key] = localChanges[dateStr][key];
                    
                    const bulan = dateStr.substring(0, 7);
                    const day = parseInt(dateStr.substring(8, 10));
                    if (!semuaDataAmalan[bulan]) semuaDataAmalan[bulan] = {};
                    if (!semuaDataAmalan[bulan][key]) semuaDataAmalan[bulan][key] = {};
                    semuaDataAmalan[bulan][key][day] = localChanges[dateStr][key];
                }
            }
            localStorage.setItem('offlineSavedChanges', JSON.stringify(offlineSavedChanges));
            clearLocalChanges();
            
            showToast('Data berhasil disimpan secara lokal (Offline)!', 'success');
            updateFormForDate._skipScroll = true;
            updateFormForDate(tanggalInput.value);
            updateSyncStatus();
        }

        function resetSaveButtons() {
            if (submitBtn) {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
            if (floatingSubmitBtn) {
                floatingSubmitBtn.innerHTML = floatingOriginalText;
                floatingSubmitBtn.disabled = false;
            }
        }
    });

    // Change Detection
    let initialFormState = '';
    
    function getCurrentFormState() {
        const data = new FormData(form);
        let formString = '';
        const sortedKeys = Array.from(data.keys()).sort();
        sortedKeys.forEach(key => {
            if(key !== 'daftar_amalan_structure' && key !== 'rawatib_details_structure' && key !== 'tanggal' && key !== 'is_ajax'){
                formString += `${key}=${data.get(key)}&`;
            }
        });
        return formString;
    }

    window.checkForChanges = function() {
        const activeDate = tanggalInput.value;
        const keys = [
            'subuh', 'dzuhur', 'ashar', 'maghrib', 'isya',
            'dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh',
            'sedekah', 'almatsurat_pagi', 'almatsurat_petang',
            'istighfar', 'tilawah', 'rawatib'
        ];

        keys.forEach(key => {
            const val = getCurrentFormValue(key);
            const day = new Date(activeDate + 'T00:00:00').getDate();
            const bulan = activeDate.substring(0, 7);
            const originalVal = semuaDataAmalan[bulan]?.[key]?.[day] ?? '';
            
            if (val !== originalVal) {
                if (!localChanges[activeDate]) localChanges[activeDate] = {};
                localChanges[activeDate][key] = val;
                
                // Update local memory cache so it persists when switching dates
                if (!semuaDataAmalan[bulan]) semuaDataAmalan[bulan] = {};
                if (!semuaDataAmalan[bulan][key]) semuaDataAmalan[bulan][key] = {};
                semuaDataAmalan[bulan][key][day] = val;
                
                // Update the table cell visually
                const cell = document.querySelector(`.table-container table tbody td[data-key="${key}"][data-day="${day}"]`);
                if (cell) {
                    updateTableCellVisually(cell, key, val);
                }
            } else {
                if (localChanges[activeDate]) {
                    delete localChanges[activeDate][key];
                }
            }
        });

        if (localChanges[activeDate] && Object.keys(localChanges[activeDate]).length === 0) {
            delete localChanges[activeDate];
        }

        localStorage.setItem('localChanges', JSON.stringify(localChanges));
        updateSyncStatus();
    };

    form.addEventListener('input', window.checkForChanges);

    // Prevent Page Reload
    window.addEventListener('beforeunload', function(e) {
        const changeCount = Object.keys(localChanges).reduce((acc, date) => {
            return acc + Object.keys(localChanges[date]).length;
        }, 0);
        if (changeCount > 0) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    // Optional Details Wrapper
    document.querySelectorAll('input[type="checkbox"][data-details-wrapper]').forEach(checkbox => {
        const wrapper = document.getElementById(checkbox.dataset.detailsWrapper);
        if(wrapper) {
            checkbox.addEventListener('change', () => {
                wrapper.style.display = checkbox.checked ? 'block' : 'none';
                if (!checkbox.checked) {
                    wrapper.querySelector('input').value = '';
                }
            });
        }
    });

    // Ayyamul Bidh Modal
    const modal = document.getElementById('ayamul_bidh_modal');
    const closeModalBtn = modal?.querySelector('.modal-close-btn');

    window.openAyyamulBidhModal = function() {
        if (!modal) return;
        document.getElementById('modal_title').textContent = ayyamulBidhInfo.title;
        document.getElementById('modal_desc').textContent = ayyamulBidhInfo.description;
        document.getElementById('modal_hadith').textContent = ayyamulBidhInfo.hadith;
        document.getElementById('modal_dates_title').textContent = ayyamulBidhInfo.dates_title;
        document.getElementById('modal_disclaimer').innerHTML = ayyamulBidhInfo.disclaimer;
        
        const list = document.getElementById('modal_dates_list');
        list.innerHTML = '';
        if (ayyamulBidhInfo.dates && ayyamulBidhInfo.dates.length > 0) {
            ayyamulBidhInfo.dates.forEach(dateStr => {
                const li = document.createElement('li');
                li.textContent = dateStr;
                list.appendChild(li);
            });
        } else {
            const li = document.createElement('li');
            li.textContent = "Jadwal tidak tersedia untuk bulan ini.";
            list.appendChild(li);
        }
        modal.classList.add('active');
    };

    if (closeModalBtn && modal) {
        const closeModal = () => modal.classList.remove('active');
        closeModalBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    // Quick Navigation Scroll
    document.querySelectorAll('.nav-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            document.querySelector(targetId).scrollIntoView({ behavior: 'smooth' });
        });
    });

    // Istighfar Slider (Vertical)
    const istighfarInput = document.getElementById('istighfar');
    const istighfarSlider = document.getElementById('istighfar_slider');
    const istighfarValueDisplay = document.getElementById('istighfar_value');
    const istighfarLabels = document.querySelectorAll('.istighfar-label');
    
    function updateIstighfarLabel(value) {
        if (istighfarLabels) {
            istighfarLabels.forEach(label => {
                const labelValue = parseInt(label.dataset.value);
                label.classList.toggle('active', labelValue === parseInt(value));
            });
        }
    }
    
    if (istighfarSlider) {
        istighfarSlider.addEventListener('input', () => {
            const value = istighfarSlider.value;
            if (istighfarInput) istighfarInput.value = value;
            if (istighfarValueDisplay) istighfarValueDisplay.textContent = value;
            updateIstighfarLabel(value);
        });
    }
    
    if (istighfarLabels) {
        istighfarLabels.forEach(label => {
            label.addEventListener('click', () => {
                const value = label.dataset.value;
                if (istighfarSlider) istighfarSlider.value = value;
                if (istighfarInput) istighfarInput.value = value;
                if (istighfarValueDisplay) istighfarValueDisplay.textContent = value;
                updateIstighfarLabel(value);
                if (typeof window.checkForChanges === 'function') {
                    window.checkForChanges();
                }
            });
        });
    }
    
    // Cycle Istighfar Value on Double-Click
    const istighfarSteps = [0, 20, 50, 100, 150, 200];
    let lastIstighfarTap = 0;
    
    function cycleIstighfarValue() {
        if (!istighfarSlider || !istighfarValueDisplay) return;
        const currentValue = parseInt(istighfarSlider.value) || 0;
        let currentIndex = istighfarSteps.findIndex(step => step >= currentValue);
        if (currentIndex === -1) currentIndex = istighfarSteps.length - 1;
        
        let nextIndex = (currentIndex + 1) % istighfarSteps.length;
        const nextValue = istighfarSteps[nextIndex];
        
        istighfarSlider.value = nextValue;
        if (istighfarInput) istighfarInput.value = nextValue;
        istighfarValueDisplay.textContent = nextValue;
        updateIstighfarLabel(nextValue);
        
        istighfarValueDisplay.style.transform = 'scale(1.15)';
        setTimeout(() => {
            istighfarValueDisplay.style.transform = 'scale(1)';
        }, 200);
        
        if (typeof window.checkForChanges === 'function') {
            window.checkForChanges();
        }
    }
    
    if (istighfarValueDisplay) {
        istighfarValueDisplay.addEventListener('dblclick', function(e) {
            e.preventDefault();
            cycleIstighfarValue();
        });
        
        istighfarValueDisplay.addEventListener('touchend', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastIstighfarTap;
            if (tapLength < 300 && tapLength > 0) {
                e.preventDefault();
                cycleIstighfarValue();
                lastIstighfarTap = 0;
            } else {
                lastIstighfarTap = currentTime;
            }
        });
    }

    // Dynamic Time-Based Background
    function updateBackgroundByTime() {
        const now = new Date();
        const hour = now.getHours();
        document.body.classList.remove('time-dawn', 'time-subuh', 'time-morning', 'time-noon', 'time-asr', 'time-maghrib', 'time-night');
        if (hour >= 0 && hour < 5) document.body.classList.add('time-dawn');
        else if (hour >= 5 && hour < 6) document.body.classList.add('time-subuh');
        else if (hour >= 6 && hour < 11) document.body.classList.add('time-morning');
        else if (hour >= 11 && hour < 15) document.body.classList.add('time-noon');
        else if (hour >= 15 && hour < 18) document.body.classList.add('time-asr');
        else if (hour >= 18 && hour < 19) document.body.classList.add('time-maghrib');
        else document.body.classList.add('time-night');
    }
    
    updateBackgroundByTime();
    setInterval(updateBackgroundByTime, 60000);

    const downloadPdfBtn = document.getElementById('download-pdf-btn');
    if (downloadPdfBtn) {
        downloadPdfBtn.addEventListener('click', downloadCurrentTableAsPdf);
    }

    // Editable cell event listener
    async function ensureActiveDate(dateStr, labelName = '', categoryName = '') {
        if (tanggalInput.value !== dateStr) {
            tanggalInput.value = dateStr;
            lastDateValue = dateStr;
            await fetchAyyamulBidh(dateStr);
            updateFormForDate(dateStr);
        }
        return true;
    }

    document.querySelector('.table-container').addEventListener('click', async function(e) {
        const cell = e.target.closest('.editable-cell');
        if (!cell) {
            const lockedCell = e.target.closest('.status-locked');
            if (lockedCell && lockedCell.dataset.key === 'ayamul_bidh') {
                window.openAyyamulBidhModal();
            }
            return;
        }
        
        const key = cell.dataset.key;
        const day = parseInt(cell.dataset.day);
        const yearMonth = tanggalInput.value.substring(0, 7);
        const dayStr = String(day).padStart(2, '0');
        const selectedDateStr = `${yearMonth}-${dayStr}`;
        
        const row = cell.closest('tr');
        let categoryName = '';
        let currRow = row;
        while (currRow) {
            const catCell = currRow.querySelector('.kategori-utama.td-ibadah');
            if (catCell) {
                categoryName = catCell.textContent.trim();
                break;
            }
            currRow = currRow.previousElementSibling;
        }

        const labelCells = row.querySelectorAll('.td-ibadah');
        const labelName = labelCells[labelCells.length - 1].textContent.trim();

        updateFormForDate._skipScroll = true;
        const dateSwitched = await ensureActiveDate(selectedDateStr, labelName, categoryName);
        if (!dateSwitched) {
            updateFormForDate._skipScroll = false;
            return;
        }

        openPopover(cell, key, day, selectedDateStr, labelName);
    });

    // Navigation Months
    const monthPrevBtn = document.getElementById('month-prev-btn');
    const monthNextBtn = document.getElementById('month-next-btn');

    function navigateMonth(direction) {
        const current = new Date(tanggalInput.value + 'T00:00:00');
        current.setMonth(current.getMonth() + direction);
        const daysInNewMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
        const newDay = Math.min(current.getDate(), daysInNewMonth);
        current.setDate(newDay);
        
        const yyyy = current.getFullYear();
        const mm = String(current.getMonth() + 1).padStart(2, '0');
        const dd = String(current.getDate()).padStart(2, '0');
        const newDateStr = `${yyyy}-${mm}-${dd}`;
        
        tanggalInput.value = newDateStr;
        tanggalInput.dispatchEvent(new Event('change'));
    }

    if (monthPrevBtn) monthPrevBtn.addEventListener('click', () => navigateMonth(-1));
    if (monthNextBtn) monthNextBtn.addEventListener('click', () => navigateMonth(1));

    // Native Datepicker Navigation
    const monthTitle = document.getElementById('month-title');
    const nativeDatepicker = document.getElementById('native-datepicker');

    if (monthTitle && nativeDatepicker) {
        monthTitle.addEventListener('click', function() {
            nativeDatepicker.value = tanggalInput.value;
            if (typeof nativeDatepicker.showPicker === 'function') {
                nativeDatepicker.showPicker();
            } else {
                nativeDatepicker.click();
            }
        });

        nativeDatepicker.addEventListener('change', function() {
            const selectedDate = this.value;
            if (selectedDate) {
                tanggalInput.value = selectedDate;
                tanggalInput.dispatchEvent(new Event('change'));
            }
        });
    }

    let lastDateValue = tanggalInput.value;
    tanggalInput.addEventListener('change', async function() {
        const newDate = this.value;
        const oldDate = lastDateValue;
        if (newDate === oldDate) return;
        
        lastDateValue = newDate;
        await fetchAyyamulBidh(newDate);
        updateFormForDate(newDate);
    });

    // Initial Load Setup
    initialFormState = getCurrentFormState();
    updateFormForDate(tanggalInput.value);
});
