import { isSeninKamisJS } from '../utils/date_helper.js';
import { 
    semuaDataAmalan, 
    offlineSavedChanges, 
    ayyamulBidhInfo, 
    prayerKeys, 
    rawatibDetailsKeys, 
    daftarAmalanStructure
} from '../providers/state_provider.js';

export function getCellColorClassJS(key, value) {
    if (!value) return 'status-empty';
    switch (key) {
        case 'subuh': case 'dzuhur': case 'ashar': case 'maghrib': case 'isya':
            if (value === 'M') return 'status-good';
            if (value === 'R-J') return 'status-ok-2';
            if (value === 'M-S' || value === 'R') return 'status-ok-1';
            if (value.toString().startsWith('Q')) return 'status-qadha';
            return 'status-empty';
        case 'rawatib':
            const parts = value.toString().split('/');
            if (parts.length === 2) {
                if (parts[0] === parts[1]) return 'status-good';
                if (parseInt(parts[0]) > 0) return 'status-ok-2';
            }
            return 'status-empty';
        case 'istighfar':
            if (value >= 200) return 'status-good';
            if (value > 0) return 'status-ok-1';
            return 'status-empty';
        case 'tilawah':
            return 'status-ok-2';
        default:
            return 'status-good';
    }
}

export function updateTableCellVisually(cell, key, newVal) {
    const isToday = cell.classList.contains('today-column');
    const colorClass = getCellColorClassJS(key, newVal);
    const dayStr = String(cell.dataset.day).padStart(2, '0');
    const tanggalInput = document.getElementById('tanggal');
    const cellDate = `${tanggalInput.value.substring(0, 7)}-${dayStr}`;
    const isUnsaved = offlineSavedChanges[cellDate]?.[key] !== undefined;
    
    cell.className = `editable-cell ${colorClass}${isToday ? ' today-column' : ''}${isUnsaved ? ' unsaved-local-change' : ''}`;
    
    let displayVal = newVal.toString().replace(/</g, "&lt;").replace(/>/g, "&gt;");
    if (key === 'istighfar') {
         if (newVal >= 200) displayVal = '✓✓';
         else if (newVal >= 100) displayVal = '✓';
         else displayVal = '';
    } else if (displayVal.startsWith('✓ (')) {
        displayVal = displayVal.replace('✓ (', '✓<br><small>(').replace(')', ')</small>');
    } else if (displayVal.startsWith('Q (')) {
        const qmatch = displayVal.match(/^Q \((\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})\)$/);
        if (qmatch) {
            const execDate = qmatch[1];
            const execTime = qmatch[2];
            if (execDate !== cellDate) {
                const dParts = execDate.split('-');
                displayVal = `Q ${dParts[2]}/${dParts[1]}<br><small>(${execTime})</small>`;
            } else {
                displayVal = `Q ${execTime}`;
            }
        } else {
            const qmatchTime = displayVal.match(/^Q \((\d{2}:\d{2})\)$/);
            if (qmatchTime) {
                displayVal = 'Q ' + qmatchTime[1];
            } else {
                displayVal = displayVal.replace('Q (', 'Q ').replace(')', '');
            }
        }
    }
    cell.innerHTML = displayVal;
}

export function scrollReportToSelectedDate(selectedDay) {
    const tableWrapper = document.querySelector('.table-wrapper');
    const tbody = document.querySelector('.table-container table tbody');
    if (!tableWrapper || !tbody) return;

    const firstDataRow = tbody.querySelector('tr');
    if (!firstDataRow) return;

    const targetCell = firstDataRow.children[2 + selectedDay];
    if (!targetCell) return;

    const targetLeft = targetCell.offsetLeft - (tableWrapper.clientWidth / 2) + (targetCell.clientWidth / 2);
    const maxScrollLeft = tableWrapper.scrollWidth - tableWrapper.clientWidth;
    const clampedLeft = Math.max(0, Math.min(targetLeft, maxScrollLeft));

    tableWrapper.scrollTo({ left: clampedLeft, behavior: 'smooth' });
}

function generateShortcutLinkJS(key, category) {
    let url = '';
    let tooltip = '';
    
    if (category === 'SHOLAT WAJIB') {
        url = 'https://al-waqt-9cdb7.web.app/';
        tooltip = 'Buka panduan Sholat Wajib';
    } else if (category === 'ALMATSURAT') {
        const action = (key === 'almatsurat_petang') ? 'sore' : 'pagi';
        url = `https://krasyid822.github.io/AlMatsurat?action=${action}`;
        tooltip = `Buka panduan Al-Ma'tsurat ${action === 'pagi' ? 'Pagi' : 'Sore'}`;
    } else if (category === 'TILAWAH') {
        url = 'https://quran.com/';
        tooltip = 'Buka Quran.com';
    } else if (category === 'ISTIGHFAR') {
        const hour = new Date().getHours();
        const action = (hour >= 4 && hour < 12) ? 'pagi' : 'sore';
        url = `https://krasyid822.github.io/AlMatsurat?action=${action}#amalan-istighfar`;
        tooltip = "Buka panduan Istighfar di Al-Ma'tsurat";
    }
    
    if (url) {
        return `<a href="${url}" target="_blank" class="legend-shortcut" title="${tooltip}"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`;
    }
    return '';
}

export function updateFormForDate(tanggalStr) {
    const form = document.getElementById('form-amalan');
    const istighfarSlider = document.getElementById('istighfar_slider');
    const istighfarValueDisplay = document.getElementById('istighfar_value');
    const tanggalInput = document.getElementById('tanggal');
    
    form.reset(); 
    
    if (istighfarSlider) istighfarSlider.value = 0;
    if (istighfarValueDisplay) istighfarValueDisplay.textContent = '0';
    
    // Reset istighfar active labels
    document.querySelectorAll('.istighfar-label').forEach(label => {
        label.classList.toggle('active', parseInt(label.dataset.value) === 0);
    });

    if (window.setPrayerSliderState) {
        prayerKeys.forEach(key => window.setPrayerSliderState(key, ''));
    }
    document.querySelectorAll('.optional-input-wrapper').forEach(w => w.style.display = 'none');

    tanggalInput.value = tanggalStr;
    
    // --- Bagian 1: Update Form Input ---
    const bulan = tanggalStr.substring(0, 7);
    const dataBulanIni = semuaDataAmalan[bulan] || {};
    const hari = new Date(tanggalStr + 'T00:00:00').getDate();

    for (const amalanKey in dataBulanIni) {
        if (dataBulanIni[amalanKey] && dataBulanIni[amalanKey][hari] !== undefined) {
            const nilai = dataBulanIni[amalanKey][hari];
            if (prayerKeys.includes(amalanKey)) {
                if (window.setPrayerSliderState) {
                    window.setPrayerSliderState(amalanKey, nilai);
                }
                continue; 
            }
            const element = form.elements[amalanKey];
            if (element) {
                 if (element.type === 'checkbox') {
                    const match = typeof nilai === 'string' && nilai.match(/^✓ \((.*)\)$/);
                    if (match) {
                        element.checked = true;
                        const wrapper = document.getElementById(element.dataset.detailsWrapper);
                        if(wrapper) {
                            wrapper.style.display = 'block';
                            wrapper.querySelector('input').value = match[1];
                        }
                    } else {
                       element.checked = (nilai === '✓');
                    }
                    element.dispatchEvent(new Event('change'));
                } else if (amalanKey === 'istighfar') {
                    const val = nilai || 0; 
                    element.value = val;
                    if (istighfarSlider) istighfarSlider.value = val;
                    if (istighfarValueDisplay) istighfarValueDisplay.textContent = val;
                    
                    document.querySelectorAll('.istighfar-label').forEach(label => {
                        label.classList.toggle('active', parseInt(label.dataset.value) === parseInt(val));
                    });
                } else if (element.tagName !== 'BUTTON') {
                    element.value = nilai; 
                }
            }
        }
    }
        
    rawatibDetailsKeys.forEach(key => {
        const element = form.elements[key];
        if(element) {
            element.checked = (dataBulanIni[key]?.[hari] === '✓');
        }
    });

    // Lock/Unlock puasa sunnah checkboxes based on date
    const seninKamisCb = document.getElementById('senin_kamis');
    const ayamulBidhCb = document.getElementById('ayamul_bidh');
    
    if (seninKamisCb && seninKamisCb.offsetParent !== null) {
        const isSK = isSeninKamisJS(tanggalStr);
        const isTasyrik = ayyamulBidhInfo && ayyamulBidhInfo.tasyrik_dates && ayyamulBidhInfo.tasyrik_dates.includes(tanggalStr);
        
        if (isTasyrik) {
            seninKamisCb.disabled = true;
            seninKamisCb.checked = false;
        } else {
            seninKamisCb.disabled = !isSK;
            if (!isSK) {
                seninKamisCb.checked = false;
            }
        }
        
        let hint = seninKamisCb.parentNode.querySelector('.puasa-hint');
        if (!hint) {
            hint = document.createElement('small');
            hint.className = 'puasa-hint';
            hint.style.marginLeft = '8px';
            hint.style.fontSize = '0.75rem';
            hint.style.fontWeight = '700';
            seninKamisCb.parentNode.appendChild(hint);
        }
        if (isTasyrik) {
            hint.textContent = ' (Hari Raya / Tasyrik - Dilarang)';
            hint.style.color = 'var(--md-sys-color-error)';
        } else if (isSK) {
            hint.textContent = ' (Hari Puasa)';
            hint.style.color = 'var(--color-success)';
        } else {
            hint.textContent = ' (Hanya Senin/Kamis)';
            hint.style.color = 'var(--md-sys-color-error)';
        }
    }
    
    if (ayamulBidhCb && ayamulBidhCb.offsetParent !== null) {
        const isAB = ayyamulBidhInfo && ayyamulBidhInfo.raw_dates && ayyamulBidhInfo.raw_dates.includes(tanggalStr);
        ayamulBidhCb.disabled = !isAB;
        if (!isAB) {
            ayamulBidhCb.checked = false;
        }
        let hint = ayamulBidhCb.parentNode.querySelector('.puasa-hint');
        if (!hint) {
            hint = document.createElement('small');
            hint.className = 'puasa-hint';
            hint.style.marginLeft = '8px';
            hint.style.fontSize = '0.75rem';
            hint.style.fontWeight = '700';
            ayamulBidhCb.parentNode.appendChild(hint);
        }
        if (isAB) {
            hint.textContent = ' (Hari Puasa Ayyamul Bidh)';
            hint.style.color = 'var(--color-success)';
        } else {
            hint.textContent = ' (Hanya tgl 13, 14, 15 Hijriyah)';
            hint.style.color = 'var(--md-sys-color-error)';
        }
    }

    const tilawahValue = dataBulanIni.tilawah?.[hari] || '';
    if (tilawahValue) {
        const parts = tilawahValue.match(/^(.*?)\s*(\d+)-?(\d+)?$/);
        if(parts) {
            form.elements.tilawah_surat.value = parts[1] ? parts[1].trim() : '';
            form.elements.tilawah_ayat_mulai.value = parts[2] || '';
            form.elements.tilawah_ayat_selesai.value = parts[3] || '';
        } else {
             form.elements.tilawah_surat.value = tilawahValue;
        }
    }
    
    // --- Bagian 2: Rebuild Laporan Table ---
    const year = parseInt(tanggalStr.substring(0, 4));
    const monthIndex = parseInt(tanggalStr.substring(5, 7)) -1; // 0-11
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const monthName = new Date(year, monthIndex, 1).toLocaleString('id-ID', { month: 'long', year: 'numeric' });

    const tableContainer = document.querySelector('.table-container');
    const h2El = tableContainer.querySelector('.month-navigator h2') || tableContainer.querySelector('h2');
    if (h2El) {
        h2El.innerHTML = `<span>Laporan Bulan: ${monthName}</span><i class="fa-regular fa-calendar-days" style="font-size: 1.1rem; color: var(--md-sys-color-primary); opacity: 0.8;"></i>`;
    }
    
    const downloadBtn = tableContainer.querySelector('.download-btn');
    if (downloadBtn) {
        const nameEl = document.querySelector('.nama-pengguna');
        const nameVal = nameEl ? nameEl.textContent.trim() : '';
        const nameParam = nameVal ? `&name=${encodeURIComponent(nameVal)}` : '';
        downloadBtn.href = `download.php?month=${bulan}${nameParam}`;
    }

    const table = tableContainer.querySelector('table');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');

    // Rebuild header
    const todayObj = new Date();
    const todayDay = todayObj.getDate();
    const todayMonth = todayObj.getMonth(); // 0-indexed
    const todayYear = todayObj.getFullYear();
    const isCurrentMonth = (year === todayYear && monthIndex === todayMonth);
    let headerHtml = `<tr><th rowspan="2">NO</th><th rowspan="2" colspan="2" class="th-ibadah">IBADAH</th><th colspan="${daysInMonth}">TANGGAL</th></tr><tr>`;
    for (let i = 1; i <= daysInMonth; i++) {
        const isTodayCol = isCurrentMonth && i === todayDay;
        headerHtml += `<th${isTodayCol ? ' class="today-column"' : ''}>${i}</th>`;
    }
    headerHtml += `</tr>`;
    thead.innerHTML = headerHtml;

    // Rebuild body
    let bodyHtml = '';
    let nomor = 1;
    for (const kategori in daftarAmalanStructure) {
        const subKategori = daftarAmalanStructure[kategori];
        const jumlahSub = Object.keys(subKategori).length;
        let isFirstRow = true;
        for (const key in subKategori) {
            const label = subKategori[key];
            bodyHtml += `<tr class="kategori-row">`;
            if(isFirstRow){
                bodyHtml += `<td class="kategori-utama td-kategori-header" rowspan="${jumlahSub}">${nomor++}</td>`;
                bodyHtml += `<td class="kategori-utama td-kategori-header td-ibadah" rowspan="${jumlahSub}">${kategori}</td>`;
            }
            const shortcutHtml = generateShortcutLinkJS(key, kategori);
            bodyHtml += `<td class="td-ibadah"><span style="white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;"><span>${label}</span>${shortcutHtml}</span></td>`;
            for (let i = 1; i <= daysInMonth; i++) {
                const value = dataBulanIni[key]?.[i] ?? '';
                const dayStr = String(i).padStart(2, '0');
                const cellDate = `${bulan}-${dayStr}`;
                
                let isLocked = false;
                if (key === 'senin_kamis') {
                    const isTasyrik = ayyamulBidhInfo && ayyamulBidhInfo.tasyrik_dates && ayyamulBidhInfo.tasyrik_dates.includes(cellDate);
                    isLocked = !isSeninKamisJS(cellDate) || isTasyrik;
                } else if (key === 'ayamul_bidh') {
                    isLocked = !ayyamulBidhInfo || !ayyamulBidhInfo.raw_dates || !ayyamulBidhInfo.raw_dates.includes(cellDate);
                }
                
                let colorClass, displayVal;
                if (isLocked) {
                    colorClass = 'status-locked';
                    displayVal = '';
                } else {
                    colorClass = getCellColorClassJS(key, value);
                    displayVal = value.toString().replace(/</g, "&lt;").replace(/>/g, "&gt;");

                    if (key === 'istighfar') {
                         if (value >= 200) displayVal = '✓✓';
                         else if (value >= 100) displayVal = '✓';
                         else displayVal = '';
                    } else if (displayVal.startsWith('✓ (')) {
                        displayVal = displayVal.replace('✓ (', '✓<br><small>(').replace(')', ')</small>');
                    } else if (displayVal.startsWith('Q (')) {
                        const qmatch = displayVal.match(/^Q \((\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})\)$/);
                        if (qmatch) {
                            const execDate = qmatch[1];
                            const execTime = qmatch[2];
                            if (execDate !== cellDate) {
                                const dParts = execDate.split('-');
                                displayVal = `Q ${dParts[2]}/${dParts[1]}<br><small>(${execTime})</small>`;
                            } else {
                                displayVal = `Q ${execTime}`;
                            }
                        } else {
                            const qmatchTime = displayVal.match(/^Q \((\d{2}:\d{2})\)$/);
                            if (qmatchTime) {
                                displayVal = 'Q ' + qmatchTime[1];
                            } else {
                                displayVal = displayVal.replace('Q (', 'Q ').replace(')', '');
                            }
                        }
                    }
                }
                
                const editableClass = isLocked ? '' : 'editable-cell';
                const isTodayCell = isCurrentMonth && i === todayDay;
                const isUnsaved = offlineSavedChanges[cellDate]?.[key] !== undefined;
                bodyHtml += `<td class="${editableClass} ${colorClass}${isTodayCell ? ' today-column' : ''}${isUnsaved ? ' unsaved-local-change' : ''}" data-key="${key}" data-day="${i}">${displayVal}</td>`;
            }
            bodyHtml += `</tr>`;
            isFirstRow = false;
        }
    }
    tbody.innerHTML = bodyHtml;
    
    if (!updateFormForDate._skipScroll) {
        setTimeout(() => {
            scrollReportToSelectedDate(hari);
        }, 150);
    }
    updateFormForDate._skipScroll = false;
    
    // Trigger reset layout styles
    const originalSubmitWrapper = document.querySelector('.original-submit-wrapper');
    if (originalSubmitWrapper) originalSubmitWrapper.style.opacity = '1';
}
updateFormForDate._skipScroll = false;
