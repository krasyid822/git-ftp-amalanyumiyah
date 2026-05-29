<?php
// FILE: shortcuts.php

/**
 * Bagian ini mencetak CSS untuk styling ikon shortcut.
 * Didefinisikan sekali saja untuk memastikan tidak ada duplikasi.
 */
if (!defined('SHORTCUT_CSS_INCLUDED')) {
    define('SHORTCUT_CSS_INCLUDED', true);
    echo <<<HTML
<style>
    .legend-shortcut {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 4px;
        color: var(--md-sys-color-primary);
        text-decoration: none;
        font-size: 0.75rem;
        font-weight: 600;
        vertical-align: middle;
        opacity: 0.7;
        transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
        padding: 2px 6px;
        border-radius: var(--md-sys-shape-corner-small);
    }
    .legend-shortcut:hover {
        opacity: 1;
        background-color: var(--md-sys-color-primary-container);
    }
</style>
HTML;
}

/**
 * Fungsi untuk menghasilkan HTML tautan shortcut berdasarkan seksi amalan.
 * @param string $section Nama seksi (cth: 'SHOLAT WAJIB').
 * @return string HTML tag <a> atau string kosong.
 */
function generate_shortcut_link($section, $key = '') {
    $url = '';
    $tooltip = '';

    switch ($section) {
        case 'SHOLAT WAJIB':
            $url = 'https://al-waqt-9cdb7.web.app/';
            /* $url = 'https://krasyid822.github.io/sholatPWA'; */
            $tooltip = 'Buka panduan Sholat Wajib';
            break;

        case 'ALMATSURAT':
            $action = ($key === 'almatsurat_petang') ? 'sore' : 'pagi';
            $url = "https://krasyid822.github.io/AlMatsurat?action={$action}";
            $tooltip = "Buka panduan Al-Ma'tsurat " . ($action === 'pagi' ? 'Pagi' : 'Sore');
            break;

        case 'TILAWAH':
            $url = 'https://quran.com/';
            $tooltip = 'Buka Quran.com';
            break;

        case 'ISTIGHFAR':
    // Pastikan timezone sudah diatur ke 'Asia/Jakarta' di awal skrip Anda
    // date_default_timezone_set('Asia/Jakarta');

    // Tentukan pagi atau sore berdasarkan jam saat ini
    $hour = (int)date('H');
    $action = ($hour >= 4 && $hour < 12) ? 'pagi' : 'sore'; // Pagi: 04:00 - 11:59

    // Perbarui anchor link dari #istighfar menjadi #amalan-istighfar
    $url = "https://krasyid822.github.io/AlMatsurat?action={$action}#amalan-istighfar";
    
    $tooltip = 'Buka panduan Istighfar di Al-Ma\'tsurat';
    break;
    }

    if (!empty($url)) {
        // Menggunakan ikon dari Font Awesome yang sudah ada di proyek Anda
        return '<a href="' . htmlspecialchars($url) . '" target="_blank" class="legend-shortcut" title="' . htmlspecialchars($tooltip) . '"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>';
    }

    return '';
}
?>