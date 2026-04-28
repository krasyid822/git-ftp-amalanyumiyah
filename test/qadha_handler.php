<?php
// FILE: qadha_handler.php (VERSI FINAL - Ikon Asli)
?>
<script>
    // --- Definisi State dan Fungsi Inti (ditempatkan di scope global) ---
    const prayerStates = {
        '':    { text: '-- Belum Diisi --',   icon: '<i class="fa-solid fa-circle-question"></i>', className: 'status-empty' },
        'M':   { text: 'Masjid (Jamaah)', icon: '<i class="fa-solid fa-mosque"></i>',       className: 'status-M' },
        'R-J': { text: 'Rumah (Jamaah)',  icon: '<i class="fa-solid fa-house-user"></i>',    className: 'status-R-J' },
        'M-S': { text: 'Masjid (Sendiri)',icon: '<i class="fa-solid fa-person-praying"></i>', className: 'status-M-S' },
        'R':   { text: 'Rumah (Sendiri)', icon: '<i class="fa-solid fa-house"></i>',        className: 'status-R' },
        'Q':   { text: 'Qadha',           icon: '<i class="fa-solid fa-clock-rotate-left"></i>', className: 'status-Q' }
    };
    const stateOrder = ['', 'M', 'R-J', 'M-S', 'R', 'Q'];

    /**
     * Fungsi utama untuk mengatur tampilan tombol sholat dan input qada.
     * @param {string} prayerKey - Kunci amalan (cth: 'subuh').
     * @param {string|null|undefined} value - Nilai dari data (cth: 'M', 'Q (..)', '' atau null).
     */
    function setPrayerButtonState(prayerKey, value) {
        const btn = document.getElementById('btn-' + prayerKey);
        const hiddenInput = document.getElementById('input-' + prayerKey);
        const qadhaWrapper = document.getElementById('qadha-details-' + prayerKey);
        
        if (!btn || !hiddenInput || !qadhaWrapper) return;

        const safeValue = value || '';
        
        qadhaWrapper.style.display = 'none';
        hiddenInput.value = safeValue;
        btn.className = 'prayer-cycle-btn';

        let state;
        if (typeof safeValue === 'string' && safeValue.startsWith('Q (')) {
            // PERUBAHAN: Kembalikan ke tampilan Qadha yang asli
            state = prayerStates['Q']; 
            
            qadhaWrapper.style.display = 'grid';
            const matches = safeValue.match(/Q \((\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})\)/);
            if (matches) {
                qadhaWrapper.querySelector('.qadha-date-input').value = matches[1];
                qadhaWrapper.querySelector('.qadha-time-input').value = matches[2];
            }
        } else {
            state = prayerStates[safeValue];
        }
        
        if (!state) {
            state = prayerStates[''];
        }

        btn.innerHTML = `${state.icon} <span>${state.text}</span>`;
        btn.classList.add(state.className);
    }

    // --- Jadikan fungsi ini global ---
    window.setPrayerButtonState = setPrayerButtonState;

    // --- Pasang event listener setelah DOM siap ---
    document.addEventListener('DOMContentLoaded', function() {
        
        document.querySelectorAll('.prayer-cycle-btn').forEach(btn => {
            const key = btn.dataset.key;
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);

            newBtn.addEventListener('click', () => {
                const hiddenInput = document.getElementById('input-' + key);
                const currentValue = hiddenInput.value;
                const currentIndex = (typeof currentValue === 'string' && currentValue.startsWith('Q (')) 
                    ? stateOrder.indexOf('Q') 
                    : stateOrder.indexOf(currentValue);

                const nextIndex = (currentIndex + 1) % stateOrder.length;
                const newValue = stateOrder[nextIndex];

                if (newValue === 'Q') {
                    const qadhaWrapper = document.getElementById('qadha-details-' + key);
                    const dateInput = qadhaWrapper.querySelector('.qadha-date-input');
                    const timeInput = qadhaWrapper.querySelector('.qadha-time-input');

                    if (!dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);
                    if (!timeInput.value) timeInput.value = new Date().toTimeString().slice(0, 5);
                    
                    const formattedValue = `Q (${dateInput.value} ${timeInput.value})`;
                    window.setPrayerButtonState(key, formattedValue);
                } else {
                    window.setPrayerButtonState(key, newValue);
                }
                
                if (typeof window.checkForChanges === 'function') window.checkForChanges();
            });
        });

        document.querySelectorAll('.qadha-date-input, .qadha-time-input').forEach(input => {
            input.addEventListener('input', () => {
                const key = input.dataset.key;
                const wrapper = document.getElementById('qadha-details-' + key);
                const dateVal = wrapper.querySelector('.qadha-date-input').value;
                const timeVal = wrapper.querySelector('.qadha-time-input').value;
                const hiddenInput = document.getElementById('input-' + key);

                if (dateVal && timeVal) {
                    hiddenInput.value = `Q (${dateVal} ${timeVal})`;
                    if (typeof window.checkForChanges === 'function') window.checkForChanges();
                }
            });
        });
    });
</script>