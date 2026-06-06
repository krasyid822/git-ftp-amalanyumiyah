// --- Definisi State untuk Slider ---
export const prayerStatesSlider = {
    0: { value: '',    text: 'Belum Diisi',      icon: '<i class="fa-solid fa-circle-question"></i>', className: '' },
    1: { value: 'M',   text: 'Masjid (Jamaah)',  icon: '<i class="fa-solid fa-mosque"></i>',          className: 'status-M' },
    2: { value: 'R-J', text: 'Rumah (Jamaah)',   icon: '<i class="fa-solid fa-house-user"></i>',      className: 'status-R-J' },
    3: { value: 'M-S', text: 'Masjid (Sendiri)', icon: '<i class="fa-solid fa-person-praying"></i>',  className: 'status-M-S' },
    4: { value: 'R',   text: 'Rumah (Sendiri)',  icon: '<i class="fa-solid fa-house"></i>',           className: 'status-R' },
    5: { value: 'Q',   text: 'Qadha',            icon: '<i class="fa-solid fa-clock-rotate-left"></i>', className: 'status-Q' }
};

// --- Keistimewaan Waktu Sholat ---
export const prayerVirtues = {
    'subuh': 'Waktu turunnya malaikat dan keberkahan rezeki. Sholat Subuh berjamaah seperti sholat semalam suntuk.',
    'dzuhur': 'Waktu dibukanya pintu surga. Rasulullah SAW selalu menunaikan sholat 4 rakaat sebelum Dzuhur.',
    'ashar': 'Waktu mustajab untuk berdoa. Barangsiapa meninggalkan sholat Ashar, maka habuslah amalannya.',
    'maghrib': 'Waktu pengampunan dosa. Tidak ada yang menghalangi antara doa dengan langit pada waktu ini.',
    'isya': 'Waktu penuh rahmat. Sholat Isya berjamaah seperti sholat setengah malam.'
};

/**
 * Fungsi untuk set nilai slider berdasarkan value dari database
 * @param {string} prayerKey - Kunci amalan (cth: 'subuh')
 * @param {string} value - Nilai dari data (cth: 'M', 'Q (..)', '' atau null)
 */
export function setPrayerSliderState(prayerKey, value) {
    const slider = document.getElementById('slider-' + prayerKey);
    const hiddenInput = document.getElementById('input-' + prayerKey);
    const indicator = document.getElementById('indicator-' + prayerKey);
    const qadhaWrapper = document.getElementById('qadha-details-' + prayerKey);
    const wrapper = slider?.closest('.prayer-slider-wrapper');
    const labels = wrapper?.querySelectorAll('.slider-label') || [];
    
    const safeValue = value || '';

    // Pastikan hidden input selalu ter-update untuk deteksi perubahan form & AJAX
    if (hiddenInput) {
        hiddenInput.value = safeValue;
    }
    
    if (!slider || !indicator) {
        return;
    }

    if (qadhaWrapper) qadhaWrapper.style.display = 'none';
    
    // Tentukan posisi slider
    let sliderPosition = 0;
    let actualValue = safeValue;
    
    if (typeof safeValue === 'string' && safeValue.startsWith('Q (')) {
        sliderPosition = 5;
        actualValue = 'Q';
        if (qadhaWrapper) {
            qadhaWrapper.style.display = 'grid';
            
            // Parse detail qadha
            const matches = safeValue.match(/Q \((\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})\)/);
            if (matches) {
                const dateInput = qadhaWrapper.querySelector('.qadha-date-input');
                const timeInput = qadhaWrapper.querySelector('.qadha-time-input');
                if (dateInput) dateInput.value = matches[1];
                if (timeInput) timeInput.value = matches[2];
            }
        }
    } else {
        // Cari posisi berdasarkan value
        for (let pos in prayerStatesSlider) {
            if (prayerStatesSlider[pos].value === safeValue) {
                sliderPosition = parseInt(pos);
                break;
            }
        }
    }
    
    // Set slider position
    slider.value = sliderPosition;
    
    // Update hidden input
    hiddenInput.value = safeValue;
    
    // Update indicator
    const state = prayerStatesSlider[sliderPosition];
    const virtue = prayerVirtues[prayerKey] || '';
    const showVirtue = sliderPosition === 0;
    const virtueHTML = showVirtue && virtue ? `<small class="prayer-virtue">${virtue}</small>` : '';
    indicator.innerHTML = `${state.icon} <span>${state.text}</span>${virtueHTML}`;
    indicator.className = 'slider-indicator ' + state.className + (showVirtue ? ' has-virtue' : ' compact');
    
    // Update active label
    labels.forEach((label, index) => {
        label.classList.toggle('active', index === sliderPosition);
    });
}

// Bind global window function
window.setPrayerSliderState = setPrayerSliderState;

export function initPrayerSliders() {
    console.log('Prayer slider handler loaded');
    
    // Setup slider event listeners
    document.querySelectorAll('.prayer-slider').forEach(slider => {
        const key = slider.dataset.key;
        const hiddenInput = document.getElementById('input-' + key);
        const indicator = document.getElementById('indicator-' + key);
        const qadhaWrapper = document.getElementById('qadha-details-' + key);
        const wrapper = slider.closest('.prayer-slider-wrapper');
        const labels = wrapper.querySelectorAll('.slider-label');
        
        slider.addEventListener('input', function() {
            const position = parseInt(this.value);
            const state = prayerStatesSlider[position];
            
            // Update indicator with virtue
            const virtue = prayerVirtues[key] || '';
            const showVirtue = position === 0;
            const virtueHTML = showVirtue && virtue ? `<small class="prayer-virtue">${virtue}</small>` : '';
            indicator.innerHTML = `${state.icon} <span>${state.text}</span>${virtueHTML}`;
            indicator.className = 'slider-indicator ' + state.className + (showVirtue ? ' has-virtue' : ' compact');
            
            labels.forEach((label, index) => {
                label.classList.toggle('active', index === position);
            });
            
            // Show/hide qadha details
            if (position === 5) { // Qadha
                qadhaWrapper.style.display = 'grid';
                
                const dateInput = qadhaWrapper.querySelector('.qadha-date-input');
                const timeInput = qadhaWrapper.querySelector('.qadha-time-input');
                
                if (!dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);
                if (!timeInput.value) timeInput.value = new Date().toTimeString().slice(0, 5);
                
                const formattedValue = `Q (${dateInput.value} ${timeInput.value})`;
                hiddenInput.value = formattedValue;
            } else {
                qadhaWrapper.style.display = 'none';
                hiddenInput.value = state.value;
            }
            
            if (typeof window.checkForChanges === 'function') {
                window.checkForChanges();
            }
        });
    });
    
    // Qadha date/time input listeners
    document.querySelectorAll('.qadha-date-input, .qadha-time-input').forEach(input => {
        input.addEventListener('input', () => {
            const key = input.dataset.key;
            const wrapper = document.getElementById('qadha-details-' + key);
            const dateVal = wrapper.querySelector('.qadha-date-input').value;
            const timeVal = wrapper.querySelector('.qadha-time-input').value;
            const hiddenInput = document.getElementById('input-' + key);
            
            if (dateVal && timeVal) {
                hiddenInput.value = `Q (${dateVal} ${timeVal})`;
                if (typeof window.checkForChanges === 'function') {
                    window.checkForChanges();
                }
            }
        });
    });
    
    // Label click to jump to position
    document.querySelectorAll('.slider-label').forEach(label => {
        label.addEventListener('click', function() {
            const value = parseInt(this.dataset.value);
            const container = this.closest('.prayer-input-group');
            const slider = container.querySelector('.prayer-slider');
            
            if (slider) {
                slider.value = value;
                slider.dispatchEvent(new Event('input'));
            }
        });
    });
    
    // Double-click/Double-tap on indicator to cycle through states
    document.querySelectorAll('.slider-indicator').forEach(indicator => {
        indicator.style.cursor = 'pointer';
        indicator.title = 'Klik/Tap 2x untuk mengubah status';
        
        let lastTap = 0;
        const doubleTapDelay = 300; // milliseconds
        
        function cycleToNextStatus() {
            const indicatorId = indicator.id;
            const key = indicatorId.replace('indicator-', '');
            const slider = document.getElementById('slider-' + key);
            
            if (slider) {
                let currentPosition = parseInt(slider.value);
                let nextPosition;
                if (currentPosition === 5) {
                    nextPosition = 0;
                } else {
                    nextPosition = (currentPosition + 1) % 6;
                }
                
                slider.value = nextPosition;
                slider.dispatchEvent(new Event('input'));
                
                indicator.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    indicator.style.transform = 'scale(1)';
                }, 200);
            }
        }
        
        indicator.addEventListener('dblclick', function(e) {
            e.preventDefault();
            cycleToNextStatus();
        });
        
        indicator.addEventListener('touchend', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;
            
            if (tapLength < doubleTapDelay && tapLength > 0) {
                e.preventDefault();
                cycleToNextStatus();
                lastTap = 0;
            } else {
                lastTap = currentTime;
            }
        });
    });
}
