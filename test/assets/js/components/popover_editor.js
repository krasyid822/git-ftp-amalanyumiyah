import { 
    semuaDataAmalan, 
    setLocalChange, 
    updateSyncStatus 
} from '../providers/state_provider.js';
import { updateTableCellVisually } from './table_view.js';

const popover = document.getElementById('table-inline-popover');
const popoverCloseBtn = document.getElementById('popover-close-btn');
const popoverTitleText = document.getElementById('popover-title-text');
const popoverBodyContent = document.getElementById('popover-body-content');

export function closePopover() {
    if (popover) {
        popover.style.display = 'none';
    }
}

if (popoverCloseBtn) {
    popoverCloseBtn.addEventListener('click', closePopover);
}

export function openPopover(originalCell, key, day, dateStr, labelName) {
    const cell = document.querySelector(`.table-container table tbody td[data-key="${key}"][data-day="${day}"]`) || originalCell;
    closePopover();
    
    // Find category from row
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
    const displayLabelName = labelCells[labelCells.length - 1].textContent.trim();

    popoverTitleText.innerHTML = `
        <div style="display: flex; flex-direction: column; line-height: 1.2;">
            <span style="font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--md-sys-color-primary); letter-spacing: 0.5px; margin-bottom: 2px;">${categoryName}</span>
            <span style="font-size: 0.9rem; font-weight: 700; color: var(--md-sys-color-on-surface); display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                <i class="fa-solid fa-pen-to-square" style="font-size: 0.8rem; color: var(--md-sys-color-primary);"></i> ${displayLabelName} - Tgl ${day}
            </span>
        </div>
    `;
    
    // Get timestamp
    const meta = semuaDataAmalan.__metadata || {};
    const timestamps = meta.timestamps || {};
    const dateTimestamps = timestamps[dateStr] || {};
    const timestampVal = dateTimestamps[key] || '';
    
    let timestampText = 'Belum pernah diisi';
    if (timestampVal) {
        const parts = timestampVal.split(' ');
        if (parts.length === 2) {
            const dateParts = parts[0].split('-');
            if (dateParts.length === 3) {
                timestampText = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]} ${parts[1]}`;
            } else {
                timestampText = timestampVal;
            }
        } else {
            timestampText = timestampVal;
        }
    }
    const tsEl = document.getElementById('popover-timestamp-text');
    if (tsEl) {
        tsEl.textContent = 'Terakhir diisi: ' + timestampText;
    }

    const currentValue = getCurrentFormValue(key);
    let bodyHtml = '';
    
    if (['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh'].includes(key)) {
        const options = [
            { val: '✓', label: '✓ Telah Dilakukan', class: 'status-good', icon: 'fa-circle-check' },
            { val: '', label: '-- Belum Dilakukan', class: 'status-empty', icon: 'fa-circle-xmark' }
        ];
        
        bodyHtml += `<div class="popover-sholat-grid" style="grid-template-columns: 1fr; gap: 8px;">`;
        options.forEach(opt => {
            const isActive = (opt.val === currentValue) ? 'active' : '';
            bodyHtml += `<button type="button" class="popover-option-btn popover-toggle-btn ${isActive}" data-value="${opt.val}" style="text-align: left; padding: 10px 14px; display: flex; align-items: center; gap: 8px;"><i class="fa-solid ${opt.icon}"></i> ${opt.label}</button>`;
        });
        bodyHtml += `</div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const val = this.dataset.value;
                saveCellData(dateStr, key, val, cell);
            });
        });
        
    } else if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
        const sholatStates = [
            { val: '', label: '--', class: 'status-empty', icon: 'fa-circle-question' },
            { val: 'M', label: 'Masjid (Jamaah)', class: 'status-good', icon: 'fa-mosque' },
            { val: 'R-J', label: 'Rumah (Jamaah)', class: 'status-ok-2', icon: 'fa-house-user' },
            { val: 'M-S', label: 'Masjid (Sendiri)', class: 'status-ok-1', icon: 'fa-person-praying' },
            { val: 'R', label: 'Rumah (Sendiri)', class: 'status-ok-1', icon: 'fa-house' },
            { val: 'Q', label: 'Qadha', class: 'status-qadha', icon: 'fa-clock-rotate-left' }
        ];
        
        let initialQDate = '';
        let initialQTime = '';
        let isQadha = false;
        if (typeof currentValue === 'string' && currentValue.startsWith('Q (')) {
            isQadha = true;
            const matches = currentValue.match(/Q \((\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})\)/);
            if (matches) {
                initialQDate = matches[1];
                initialQTime = matches[2];
            }
        } else if (currentValue === 'Q') {
            isQadha = true;
        }
        
        bodyHtml += `<div class="popover-sholat-grid">`;
        sholatStates.forEach(s => {
            const isActive = (s.val === 'Q' && isQadha) || (!isQadha && s.val === currentValue) ? 'active' : '';
            bodyHtml += `<button type="button" class="popover-option-btn popover-sholat-btn ${isActive}" data-value="${s.val}"><i class="fa-solid ${s.icon}"></i> ${s.label}</button>`;
        });
        bodyHtml += `</div>`;
        
        bodyHtml += `<div class="popover-qadha-fields" id="popover-qadha-wrapper" style="display: ${isQadha ? 'grid' : 'none'};">
            <input type="date" class="popover-input" id="popover-qadha-date" value="${initialQDate || new Date().toISOString().slice(0, 10)}" aria-label="Tanggal Qadha">
            <input type="time" class="popover-input" id="popover-qadha-time" value="${initialQTime || new Date().toTimeString().slice(0, 5)}" aria-label="Waktu Qadha">
        </div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        popoverBodyContent.querySelectorAll('.popover-sholat-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                popoverBodyContent.querySelectorAll('.popover-sholat-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const val = this.dataset.value;
                const qWrapper = document.getElementById('popover-qadha-wrapper');
                if (val === 'Q') {
                    qWrapper.style.display = 'grid';
                    triggerQadhaSave();
                } else {
                    qWrapper.style.display = 'none';
                    saveCellData(dateStr, key, val, cell);
                }
            });
        });
        
        function triggerQadhaSave() {
            const qDate = document.getElementById('popover-qadha-date').value;
            const qTime = document.getElementById('popover-qadha-time').value;
            if (qDate && qTime) {
                saveCellData(dateStr, key, `Q (${qDate} ${qTime})`, cell, false);
            }
        }

        const qDateInput = document.getElementById('popover-qadha-date');
        const qTimeInput = document.getElementById('popover-qadha-time');
        if (qDateInput && qTimeInput) {
            [qDateInput, qTimeInput].forEach(inp => {
                inp.addEventListener('input', triggerQadhaSave);
            });
        }
        
    } else if (key === 'rawatib') {
        const rawatibSubKeys = {
            'rawatib_subuh_q': '2 Rakaat sebelum Subuh ←🌅',
            'rawatib_dzuhur_q': '2 atau 4 Rakaat sebelum Dzuhur ←☀️',
            'rawatib_dzuhur_b': '2 Rakaat setelah Dzuhur →☀️',
            'rawatib_maghrib_b': '2 Rakaat setelah Maghrib →🌇',
            'rawatib_isya_b': '2 Rakaat setelah Isya →☪️'
        };
        
        bodyHtml += `<div style="display: flex; flex-direction: column; gap: 8px;">`;
        for (const rkey in rawatibSubKeys) {
            const form = document.getElementById('form-amalan');
            const isChecked = (form.elements[rkey] && form.elements[rkey].checked) ? 'checked' : '';
            bodyHtml += `<div class="checkbox-group" style="font-size: 0.8rem;">
                <input type="checkbox" id="popover-${rkey}" data-rkey="${rkey}" ${isChecked}>
                <label for="popover-${rkey}">${rawatibSubKeys[rkey]}</label>
            </div>`;
        }
        bodyHtml += `</div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        function triggerRawatibSave() {
            const details = {};
            popoverBodyContent.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                details[cb.dataset.rkey] = cb.checked ? '✓' : '';
            });
            saveCellData(dateStr, key, JSON.stringify(details), cell, false);
        }
        
        popoverBodyContent.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', triggerRawatibSave);
        });
        
    } else if (key === 'istighfar') {
        const istighfarSteps = [0, 20, 50, 100, 150, 200];
        const currentIstighfarNum = parseInt(currentValue) || 0;
        
        bodyHtml += `<div class="popover-sholat-grid">`;
        istighfarSteps.forEach(val => {
            const isActive = (currentIstighfarNum === val) ? 'active' : '';
            bodyHtml += `<button type="button" class="popover-option-btn popover-istighfar-btn ${isActive}" data-value="${val}">${val}</button>`;
        });
        bodyHtml += `</div>`;
        
        bodyHtml += `<div style="margin-top: 4px;">
            <label style="font-size: 0.75rem; margin-bottom: 4px; display: block;">Atau angka kustom:</label>
            <input type="number" class="popover-input" id="popover-istighfar-custom" min="0" max="1000" value="${currentValue || ''}" placeholder="Angka kustom">
        </div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        const customInput = document.getElementById('popover-istighfar-custom');
        
        popoverBodyContent.querySelectorAll('.popover-istighfar-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const val = this.dataset.value;
                customInput.value = val;
                saveCellData(dateStr, key, val, cell);
            });
        });
        
        customInput.addEventListener('input', function() {
            const val = this.value || 0;
            saveCellData(dateStr, key, val, cell, false);
        });
        
    } else if (key === 'tilawah') {
        let surah = '';
        let startAyat = '';
        let endAyat = '';
        if (currentValue) {
            const parts = currentValue.match(/^(.*?)\s*(\d+)-?(\d+)?$/);
            if (parts) {
                surah = parts[1] ? parts[1].trim() : '';
                startAyat = parts[2] || '';
                endAyat = parts[3] || '';
            } else {
                surah = currentValue;
            }
        }
        
        bodyHtml += `<div style="display: flex; flex-direction: column; gap: 8px;">
            <div>
                <label style="font-size: 0.75rem; margin-bottom: 2px; display: block;">Nama Surat:</label>
                <input type="text" class="popover-input" id="popover-tilawah-surat" value="${surah.replace(/"/g, '&quot;')}" placeholder="Contoh: Al-Baqarah">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <div>
                    <label style="font-size: 0.75rem; margin-bottom: 2px; display: block;">Ayat Ke:</label>
                    <input type="text" class="popover-input" id="popover-tilawah-mulai" value="${startAyat}" placeholder="Mulai">
                </div>
                <div>
                    <label style="font-size: 0.75rem; margin-bottom: 2px; display: block;">Sampai:</label>
                    <input type="text" class="popover-input" id="popover-tilawah-selesai" value="${endAyat}" placeholder="Selesai">
                </div>
            </div>
        </div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        function triggerTilawahSave() {
            const sVal = document.getElementById('popover-tilawah-surat').value.trim();
            const startVal = document.getElementById('popover-tilawah-mulai').value.trim();
            const endVal = document.getElementById('popover-tilawah-selesai').value.trim();
            
            let text = sVal;
            if (text && startVal) {
                text += ' ' + startVal;
                if (endVal) {
                    text += '-' + endVal;
                }
            }
            saveCellData(dateStr, key, text, cell, false);
        }
        
        const tSurat = document.getElementById('popover-tilawah-surat');
        const tMulai = document.getElementById('popover-tilawah-mulai');
        const tSelesai = document.getElementById('popover-tilawah-selesai');
        
        [tSurat, tMulai, tSelesai].forEach(inp => {
            inp.addEventListener('input', triggerTilawahSave);
        });
        
    } else if (['sedekah', 'almatsurat_pagi', 'almatsurat_petang'].includes(key)) {
        let isChecked = false;
        let detailVal = '';
        const match = typeof currentValue === 'string' && currentValue.match(/^✓ \((.*)\)$/);
        if (match) {
            isChecked = true;
            detailVal = match[1];
        } else {
            isChecked = (currentValue === '✓');
        }
        
        let placeholderText = 'Detail opsional';
        if (key === 'sedekah') {
            placeholderText = 'Cth: Uang, makanan';
        } else if (key === 'almatsurat_pagi' || key === 'almatsurat_petang') {
            placeholderText = 'Cth: Sampai doa almatsurat';
        }
        
        const options = [
            { val: '✓', label: '✓ Telah Dilakukan', class: 'status-good', icon: 'fa-circle-check' },
            { val: '', label: '-- Belum Dilakukan', class: 'status-empty', icon: 'fa-circle-xmark' }
        ];
        
        bodyHtml += `<div class="popover-sholat-grid" style="grid-template-columns: 1fr; gap: 8px; margin-bottom: 8px;">`;
        options.forEach(opt => {
            const isActive = (opt.val === '✓' && isChecked) || (opt.val === '' && !isChecked) ? 'active' : '';
            bodyHtml += `<button type="button" class="popover-option-btn popover-toggle-btn ${isActive}" data-value="${opt.val}" style="text-align: left; padding: 10px 14px; display: flex; align-items: center; gap: 8px;"><i class="fa-solid ${opt.icon}"></i> ${opt.label}</button>`;
        });
        bodyHtml += `</div>`;
        
        bodyHtml += `<div id="popover-detail-wrapper" style="margin-top: 8px;">
            <input type="text" class="popover-input" id="popover-detail-text" value="${detailVal.replace(/"/g, '&quot;')}" placeholder="${placeholderText}" style="width: 100%; box-sizing: border-box;">
        </div>`;
        
        popoverBodyContent.innerHTML = bodyHtml;
        
        const detailText = document.getElementById('popover-detail-text');
        let popoverStatus = isChecked ? '✓' : '';
        
        function triggerDetailsSave() {
            let finalValue = '';
            if (popoverStatus === '✓') {
                const text = detailText.value.trim();
                finalValue = text ? `✓ (${text})` : '✓';
            }
            saveCellData(dateStr, key, finalValue, cell, false);
        }
        
        popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                popoverStatus = this.dataset.value;
                triggerDetailsSave();
            });
        });
        
        detailText.addEventListener('input', triggerDetailsSave);
    }
    
    // Positioning Popover
    popover.style.left = '0px';
    popover.style.top = '0px';
    popover.style.display = 'block';
    popover.style.visibility = 'hidden';
    
    const popoverWidth = popover.offsetWidth;
    const popoverHeight = popover.offsetHeight;
    
    const cellRect = cell.getBoundingClientRect();
    const offsetParent = popover.offsetParent || document.body;
    const parentRect = offsetParent.getBoundingClientRect();
    
    let popoverViewportLeft = cellRect.left + (cellRect.width / 2) - (popoverWidth / 2);
    let popoverViewportTop = cellRect.bottom + 8;
    
    if (popoverViewportLeft < 10) {
        popoverViewportLeft = 10;
    } else if (popoverViewportLeft + popoverWidth > window.innerWidth - 10) {
        popoverViewportLeft = window.innerWidth - popoverWidth - 10;
    }
    
    if (popoverViewportTop + popoverHeight > window.innerHeight - 10) {
        popoverViewportTop = cellRect.top - popoverHeight - 8;
    }
    
    const popoverLeft = popoverViewportLeft - parentRect.left;
    const popoverTop = popoverViewportTop - parentRect.top;
    
    popover.style.left = `${popoverLeft}px`;
    popover.style.top = `${popoverTop}px`;
    popover.style.visibility = 'visible';
}

export function getCurrentFormValue(key) {
    const form = document.getElementById('form-amalan');
    if (['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh'].includes(key)) {
        return form.elements[key] && form.elements[key].checked ? '✓' : '';
    }
    if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
        const input = document.getElementById(`input-${key}`);
        return input ? input.value : '';
    }
    if (key === 'rawatib') {
        const rawatibSubKeys = ['rawatib_subuh_q', 'rawatib_dzuhur_q', 'rawatib_dzuhur_b', 'rawatib_maghrib_b', 'rawatib_isya_b'];
        let done = 0;
        rawatibSubKeys.forEach(rkey => {
            if (form.elements[rkey] && form.elements[rkey].checked) done++;
        });
        return done > 0 ? `${done}/5` : '';
    }
    if (key === 'istighfar') {
        return form.elements[key] ? form.elements[key].value || '' : '';
    }
    if (key === 'tilawah') {
        const sVal = form.elements.tilawah_surat.value.trim();
        const startVal = form.elements.tilawah_ayat_mulai.value.trim();
        const endVal = form.elements.tilawah_ayat_selesai.value.trim();
        let text = sVal;
        if (text && startVal) {
            text += ' ' + startVal;
            if (endVal) {
                text += '-' + endVal;
            }
        }
        return text;
    }
    if (['sedekah', 'almatsurat_pagi', 'almatsurat_petang'].includes(key)) {
        const cb = form.elements[key];
        if (cb && cb.checked) {
            const detailInput = document.getElementById(`${key}_detail`);
            const text = detailInput ? detailInput.value.trim() : '';
            return text ? `✓ (${text})` : '✓';
        }
        return '';
    }
    return '';
}

export function saveCellData(dateStr, key, value, originalCell, shouldClose = true) {
    const day = originalCell.dataset.day;
    const cell = document.querySelector(`.table-container table tbody td[data-key="${key}"][data-day="${day}"]`) || originalCell;
    const rawatibDetailsKeys = ['rawatib_subuh_q', 'rawatib_dzuhur_q', 'rawatib_dzuhur_b', 'rawatib_maghrib_b', 'rawatib_isya_b'];
    const form = document.getElementById('form-amalan');
    const istighfarSlider = document.getElementById('istighfar_slider');
    const istighfarValueDisplay = document.getElementById('istighfar_value');

    if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
        if (window.setPrayerSliderState) window.setPrayerSliderState(key, value);
    } else if (['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh'].includes(key)) {
        const cb = form.elements[key];
        if (cb) {
            cb.checked = (value === '✓');
            cb.dispatchEvent(new Event('change'));
        }
    } else if (key === 'rawatib') {
        const details = JSON.parse(value);
        let done = 0;
        rawatibDetailsKeys.forEach(rkey => {
            const cb = form.elements[rkey];
            if (cb) {
                cb.checked = !!details[rkey];
                cb.dispatchEvent(new Event('change'));
            }
            if (details[rkey] === '✓') done++;
        });
        value = done > 0 ? `${done}/5` : '';
    } else if (key === 'istighfar') {
        const val = parseInt(value) || 0;
        const element = form.elements[key];
        if (element) {
            element.value = val;
        }
        if (istighfarSlider) istighfarSlider.value = val;
        if (istighfarValueDisplay) istighfarValueDisplay.textContent = val;
        
        document.querySelectorAll('.istighfar-label').forEach(label => {
            label.classList.toggle('active', parseInt(label.dataset.value) === val);
        });
    } else if (key === 'tilawah') {
        let surah = '';
        let start = '';
        let end = '';
        if (value) {
            const parts = value.match(/^(.*?)\s*(\d+)-?(\d+)?$/);
            if (parts) {
                surah = parts[1] ? parts[1].trim() : '';
                start = parts[2] || '';
                end = parts[3] || '';
            } else {
                surah = value;
            }
        }
        form.elements.tilawah_surat.value = surah;
        form.elements.tilawah_ayat_mulai.value = start;
        form.elements.tilawah_ayat_selesai.value = end;
    } else if (['sedekah', 'almatsurat_pagi', 'almatsurat_petang'].includes(key)) {
        let isChecked = false;
        let detailVal = '';
        const match = typeof value === 'string' && value.match(/^✓ \((.*)\)$/);
        if (match) {
            isChecked = true;
            detailVal = match[1];
        } else {
            isChecked = (value === '✓');
        }
        const cb = form.elements[key];
        if (cb) {
            cb.checked = isChecked;
            cb.dispatchEvent(new Event('change'));
            const wrapper = document.getElementById(cb.dataset.detailsWrapper);
            if (wrapper) {
                wrapper.style.display = isChecked ? 'block' : 'none';
                wrapper.querySelector('input').value = detailVal;
            }
        }
    }

    setLocalChange(dateStr, key, value);

    // Update semuaDataAmalan local cache
    const bulan = dateStr.substring(0, 7);
    const dayInt = parseInt(dateStr.substring(8, 10));
    if (!semuaDataAmalan[bulan]) semuaDataAmalan[bulan] = {};
    if (!semuaDataAmalan[bulan][key]) semuaDataAmalan[bulan][key] = {};
    semuaDataAmalan[bulan][key][dayInt] = value;

    // Cache last modified timestamp locally
    if (!semuaDataAmalan.__metadata) semuaDataAmalan.__metadata = {};
    if (!semuaDataAmalan.__metadata.timestamps) semuaDataAmalan.__metadata.timestamps = {};
    if (!semuaDataAmalan.__metadata.timestamps[dateStr]) semuaDataAmalan.__metadata.timestamps[dateStr] = {};
    const localNowStr = new Date().toISOString().replace('T', ' ').substring(0, 19);
    semuaDataAmalan.__metadata.timestamps[dateStr][key] = localNowStr;

    updateTableCellVisually(cell, key, value);

    if (shouldClose) {
        closePopover();
    }

    updateSyncStatus();

    cell.classList.add('cell-save-success');
    setTimeout(() => cell.classList.remove('cell-save-success'), 800);
}

// Global click listener to close popover
document.addEventListener('click', function(e) {
    if (popover && popover.style.display === 'block') {
        if (!popover.contains(e.target) && !e.target.closest('.editable-cell') && !e.target.closest('.modal-content') && !e.target.closest('.info-btn')) {
            closePopover();
        }
    }
});
