<?php
// FILE: index.php (VERSI FINAL DENGAN SEMUA FITUR TERMASUK PERHITUNGAN ASLI VIA CLASS)

// --- PENGATURAN DASAR ---
date_default_timezone_set('Asia/Jakarta');
// MODIFIKASI 1: Cek apakah $dataFile sudah didefinisikan oleh file pemanggil.
// Jika belum, gunakan default (file di folder ini sendiri).
if (!isset($dataFile)) {
    $dataFile = __DIR__ . '/data_amalan.json';
}
// __DIR__ adalah konstanta PHP yang berarti "direktori dari file ini".
// Ini memastikan path akan selalu benar, tidak peduli dari mana file ini di-include.


// --- AJAX HANDLER ---
// Bagian ini akan menangani request yang datang dari JavaScript (fetch)
if (isset($_POST['is_ajax'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Aksi tidak diketahui.'];

    // --- A. AJAX UNTUK MENYIMPAN DATA ---
    if ($_POST['is_ajax'] === 'save_data' && isset($_POST['tanggal'])) {
        $tanggal = $_POST['tanggal'];
        $bulan = date('Y-m', strtotime($tanggal));
        $hari = (int)date('d', strtotime($tanggal));

        $daftar_amalan_ajax = json_decode($_POST['daftar_amalan_structure'], true);
        $rawatib_details_ajax = json_decode($_POST['rawatib_details_structure'], true);
        
        $dataAmalan = bacaDataAmalan($dataFile);

        // Proses semua amalan standar
        foreach ($daftar_amalan_ajax as $kategori) {
            foreach ($kategori as $key => $label) {
                $dataAmalan[$bulan][$key][$hari] = $_POST[$key] ?? '';
            }
        }
        
        // Proses detail
        if (!empty($_POST['sedekah']) && !empty($_POST['sedekah_detail'])) $dataAmalan[$bulan]['sedekah'][$hari] = '✓ (' . trim($_POST['sedekah_detail']) . ')';
        if (!empty($_POST['almatsurat_pagi']) && !empty($_POST['almatsurat_pagi_detail'])) $dataAmalan[$bulan]['almatsurat_pagi'][$hari] = '✓ (' . trim($_POST['almatsurat_pagi_detail']) . ')';
        if (!empty($_POST['almatsurat_petang']) && !empty($_POST['almatsurat_petang_detail'])) $dataAmalan[$bulan]['almatsurat_petang'][$hari] = '✓ (' . trim($_POST['almatsurat_petang_detail']) . ')';
        
        // Proses Tilawah
        if (!empty($_POST['tilawah_surat'])) {
            $tilawah_text = trim($_POST['tilawah_surat']);
            if(!empty($_POST['tilawah_ayat_mulai'])) {
                $tilawah_text .= ' ' . trim($_POST['tilawah_ayat_mulai']);
                if(!empty($_POST['tilawah_ayat_selesai'])) $tilawah_text .= '-' . trim($_POST['tilawah_ayat_selesai']);
            }
            $dataAmalan[$bulan]['tilawah'][$hari] = bi_normalize_tilawah_text($tilawah_text);
        } else {
            $dataAmalan[$bulan]['tilawah'][$hari] = '';
        }

        // Proses Rawatib
        $rawatib_done = 0;
        foreach ($rawatib_details_ajax as $key => $label) {
            if (!empty($_POST[$key])) {
                $rawatib_done++;
                $dataAmalan[$bulan][$key][$hari] = '✓';
            } else {
                $dataAmalan[$bulan][$key][$hari] = '';
            }
        }
        $dataAmalan[$bulan]['rawatib'][$hari] = ($rawatib_done > 0) ? $rawatib_done . '/' . count($rawatib_details_ajax) : '';

        // Proses Istighfar
        $istighfar_val = (int)($_POST['istighfar'] ?? 0);
        $dataAmalan[$bulan]['istighfar'][$hari] = $istighfar_val > 0 ? $istighfar_val : '';

        simpanDataAmalan($dataFile, $dataAmalan);
        
        $response = [
            'status' => 'success',
            'message' => "Data untuk " . date('d F Y', strtotime($tanggal)) . " berhasil disimpan!",
            'updated_data' => bacaDataAmalan($dataFile) // Kirim kembali data terbaru
        ];
    }
    
    // --- B. AJAX UNTUK MENGAMBIL INFO AYYAMUL BIDH ---
    if ($_POST['is_ajax'] === 'get_ayyamul_bidh' && isset($_POST['tanggal'])) {
        $selectedDate = new DateTime($_POST['tanggal']);
        $response = getAyyamulBidhInfoFromClass($selectedDate);
    }

    echo json_encode($response);
    exit; // Hentikan eksekusi script setelah mengirim response JSON
}


// --- FUNGSI BARU UNTUK MENGGUNAKAN CLASS PERHITUNGAN ---
function getAyyamulBidhInfoFromClass($date = null) {
    require_once __DIR__ . '/AyamulBidhCalc.php';
    $calculator = new AyyamulBidhCalculator();
    
    // Gunakan tanggal yang diberikan atau tanggal hari ini jika null
    $currentDate = $date ?? new DateTime();
    $bidhData = $calculator->getAyyamulBidhDates($currentDate);
    
    $jadwal_puasa_final = [];
    if (empty($bidhData['error']) && !empty($bidhData['dates'])) {
        foreach ($bidhData['dates'] as $dateInfo) {
            $jadwal_puasa_final[] = $dateInfo['formatted'];
        }
    } else if (!empty($bidhData['error'])) {
         $jadwal_puasa_final = [$bidhData['error']];
    }

    return [
        'title'       => 'Puasa Ayyamul Bidh (Puasa Hari-hari Putih)',
        'description' => 'Puasa sunnah yang dilaksanakan pada tanggal 13, 14, dan 15 setiap bulan Hijriah. Disebut hari-hari putih karena pada malam-malam tersebut, bulan bersinar terang menyinari bumi.',
        'hadith'      => 'Dari Abu Dzar, Rasulullah shallallahu ‘alaihi wa sallam bersabda padanya, “Jika engkau ingin berpuasa tiga hari setiap bulannya, maka berpuasalah pada tanggal 13, 14, dan 15 (dari bulan Hijriyah).” (HR. Tirmidzi dan An Nasa’i)',
        'dates_title' => 'Perkiraan Jadwal Bulan Ini (' . ($bidhData['current_hijri_month'] ?? '') . ' ' . ($bidhData['current_hijri_year'] ?? '') . '):',
        'dates'       => $jadwal_puasa_final,
        'disclaimer'  => 'Perhitungan ini menggunakan algoritma internal dan akurasinya bisa berbeda satu hari, tergantung metode penentuan awal bulan (rukyat/hisab) di wilayah Anda.'
    ];
}

// Panggil fungsi untuk mendapatkan data dinamis SAAT HALAMAN PERTAMA KALI DIMUAT
$ayyamul_bidh_info = getAyyamulBidhInfoFromClass();


// --- STRUKTUR DATA AMALAN ---
$daftar_amalan = [
    'SHOLAT WAJIB' => [
        'subuh' => 'Subuh', 'dzuhur' => 'Dzuhur', 'ashar' => 'Ashar', 'maghrib' => 'Maghrib', 'isya' => 'Isya'
    ],
    'SHOLAT SUNNAH' => [
        'rawatib' => 'Rawatib', 'dhuha' => 'Dhuha', 'tahajud' => 'Tahajud'
    ],
    'PUASA SUNNAH' => [
        'senin_kamis' => 'Senin/Kamis', 'ayamul_bidh' => 'Ayyamul Bidh'
    ],
    'TILAWAH' => [
        'tilawah' => 'Tilawah Quran'
    ],
    'SEDEKAH' => [
        'sedekah' => 'Sedekah'
    ],
    'ALMATSURAT' => [
        'almatsurat_pagi' => 'Al-Matsurat Pagi', 'almatsurat_petang' => 'Al-Matsurat Petang'
    ],
    'ISTIGHFAR' => [
        'istighfar' => 'Istighfar'
    ]
];

// Opsi detail untuk Rawatib (HANYA MU'AKAD SESUAI ANJURAN)
$rawatib_details = [
    'rawatib_subuh_q' => '2 Rakaat sebelum Subuh ←🌅',
    'rawatib_dzuhur_q' => '2 atau 4 Rakaat sebelum Dzuhur ←☀️',
    'rawatib_dzuhur_b' => '2 Rakaat setelah Dzuhur →☀️',
    'rawatib_maghrib_b' => '2 Rakaat setelah Maghrib →🌇',
    'rawatib_isya_b' => '2 Rakaat setelah Isya →☪️'
];


// --- FUNGSI-FUNGSI ---
function bacaDataAmalan($file) {
    if (!file_exists($file)) file_put_contents($file, '{}');
    $dataJson = file_get_contents($file);
    $data = json_decode($dataJson, true);
    if (!is_array($data)) {
        return [];
    }

    if (bi_repair_tilawah_storage($data)) {
        simpanDataAmalan($file, $data);
    }

    return $data;
}

function simpanDataAmalan($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function bi_normalize_quran_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/^(surah|surat|qs)\s+/iu', '', $value) ?? $value;
    $value = str_replace(['’', '`', '´'], "'", $value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);

    return is_string($value) ? $value : '';
}

function bi_load_quran_reference_map(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    $csvFile = __DIR__ . '/dataset_halaman_quran.csv';
    if (!is_file($csvFile)) {
        return $cache;
    }

    $handle = fopen($csvFile, 'r');
    if ($handle === false) {
        return $cache;
    }

    fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        foreach ([$row[1] ?? '', $row[3] ?? ''] as $surahName) {
            $surahName = trim((string) $surahName);
            if ($surahName === '') {
                continue;
            }

            $cache[bi_normalize_quran_key($surahName)] = $surahName;
        }
    }

    fclose($handle);

    return $cache;
}

function bi_match_quran_reference_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '') {
        return '';
    }

    $reference = bi_load_quran_reference_map();
    $normalizedKey = bi_normalize_quran_key($name);
    if ($normalizedKey === '' || empty($reference)) {
        return $name;
    }

    if (isset($reference[$normalizedKey])) {
        return $reference[$normalizedKey];
    }

    $bestName = null;
    $bestDistance = null;
    foreach ($reference as $candidateKey => $candidateName) {
        $distance = levenshtein($normalizedKey, $candidateKey);
        if ($bestDistance === null || $distance < $bestDistance) {
            $bestDistance = $distance;
            $bestName = $candidateName;
        }
    }

    $limit = max(1, (int) floor(strlen($normalizedKey) * 0.25));
    return $bestName !== null && $bestDistance !== null && $bestDistance <= $limit ? $bestName : $name;
}

function bi_normalize_quran_surah_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '') {
        return '';
    }

    return bi_match_quran_reference_name($name);
}

function bi_normalize_tilawah_text(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }

    $segments = preg_split('/\s*,\s*/u', $text);
    if ($segments === false || $segments === []) {
        $segments = [$text];
    }

    $normalizedSegments = [];
    foreach ($segments as $segment) {
        $segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
        $segment = preg_replace('/\s*[:：]\s*/u', ' ', $segment) ?? $segment;
        $segment = preg_replace('/\b(ayat|ayah)\b/iu', ' ', $segment) ?? $segment;
        $segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
        if ($segment === '') {
            continue;
        }

        $surahPart = $segment;
        $ayatSuffix = '';
        if (preg_match('/^(.*?)(\s+\d.*)$/u', $segment, $match)) {
            $surahPart = trim($match[1]);
            $ayatSuffix = ' ' . trim($match[2]);
        }

        $normalizedSegments[] = bi_normalize_quran_surah_name($surahPart) . $ayatSuffix;
    }

    return implode(', ', $normalizedSegments);
}

function bi_repair_tilawah_storage(array &$data): bool
{
    $changed = false;

    foreach ($data as $month => &$monthData) {
        if (!is_array($monthData) || empty($monthData['tilawah']) || !is_array($monthData['tilawah'])) {
            continue;
        }

        foreach ($monthData['tilawah'] as $day => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $normalizedValue = bi_normalize_tilawah_text($value);
            if ($normalizedValue !== $value) {
                $monthData['tilawah'][$day] = $normalizedValue;
                $changed = true;
            }
        }
    }

    unset($monthData);

    return $changed;
}

// Fungsi untuk memberikan kelas warna pada sel tabel
function getCellColorClass($key, $value) {
    if (empty($value)) return 'status-empty';

    switch ($key) {
        case 'subuh':
        case 'dzuhur':
        case 'ashar':
        case 'maghrib':
        case 'isya':
            if ($value == 'M') return 'status-good'; // Masjid Jamaah
            if ($value == 'R-J') return 'status-ok-2'; // Rumah Jamaah
            if ($value == 'M-S') return 'status-ok-1'; // Masjid Sendiri
            if ($value == 'R') return 'status-ok-1'; // Rumah Sendiri
            if (strpos($value, 'Q') === 0) return 'status-qadha'; // Qadha (baik 'Q' maupun 'Q (...)')
            return 'status-empty';
        case 'rawatib':
            // Pastikan value adalah string dan mengandung '/'
            if (is_string($value) && strpos($value, '/') !== false) {
                 list($done, $total) = explode('/', $value);
                 if ($done == $total) return 'status-good';
                 if ($done > 0) return 'status-ok-2';
            }
            return 'status-empty';
        case 'istighfar':
            if ($value >= 200) return 'status-good';
            if ($value >= 100) return 'status-ok-2';
            return 'status-empty';
        case 'tilawah':
            return 'status-ok-2'; // Setiap tilawah dianggap baik
        default:
            return 'status-good'; // Untuk checkbox ✓
    }
}


// --- PROSES SUBMIT FORM (HANYA UNTUK NON-JAVASCRIPT FALLBACK) ---
$pesan_sukses = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['is_ajax'])) {
    $tanggal = $_POST['tanggal'];
    $bulan = date('Y-m', strtotime($tanggal));
    $hari = (int)date('d', strtotime($tanggal));

    $dataAmalan = bacaDataAmalan($dataFile);

    // Proses semua amalan standar (Sholat Wajib, Dhuha, Tahajud, dll)
    foreach ($daftar_amalan as $kategori) {
        foreach ($kategori as $key => $label) {
            $dataAmalan[$bulan][$key][$hari] = $_POST[$key] ?? '';
        }
    }
    
    // --- PROSES INPUT DETAIL (TIDAK MERUSAK INTI) ---
    // 1. Sedekah
    if (!empty($_POST['sedekah']) && !empty($_POST['sedekah_detail'])) {
        $dataAmalan[$bulan]['sedekah'][$hari] = '✓ (' . trim($_POST['sedekah_detail']) . ')';
    }
    // 2. Al-Matsurat Pagi
    if (!empty($_POST['almatsurat_pagi']) && !empty($_POST['almatsurat_pagi_detail'])) {
        $dataAmalan[$bulan]['almatsurat_pagi'][$hari] = '✓ (' . trim($_POST['almatsurat_pagi_detail']) . ')';
    }
    // 3. Al-Matsurat Petang
    if (!empty($_POST['almatsurat_petang']) && !empty($_POST['almatsurat_petang_detail'])) {
        $dataAmalan[$bulan]['almatsurat_petang'][$hari] = '✓ (' . trim($_POST['almatsurat_petang_detail']) . ')';
    }


    // Proses input khusus
    // 1. Tilawah: gabungkan surat dan ayat
    if (!empty($_POST['tilawah_surat'])) {
        $tilawah_text = trim($_POST['tilawah_surat']);
        if(!empty($_POST['tilawah_ayat_mulai'])) {
            $tilawah_text .= ' ' . trim($_POST['tilawah_ayat_mulai']);
            if(!empty($_POST['tilawah_ayat_selesai'])) {
                 $tilawah_text .= '-' . trim($_POST['tilawah_ayat_selesai']);
            }
        }
        $dataAmalan[$bulan]['tilawah'][$hari] = bi_normalize_tilawah_text($tilawah_text);
    } else {
        $dataAmalan[$bulan]['tilawah'][$hari] = '';
    }

    // 2. Rawatib: hitung jumlah yang dichecklist
    $rawatib_done = 0;
    foreach ($rawatib_details as $key => $label) {
        if (!empty($_POST[$key])) {
            $rawatib_done++;
            $dataAmalan[$bulan][$key][$hari] = '✓'; // Simpan detailnya juga
        } else {
             $dataAmalan[$bulan][$key][$hari] = '';
        }
    }
    $dataAmalan[$bulan]['rawatib'][$hari] = ($rawatib_done > 0) ? $rawatib_done . '/' . count($rawatib_details) : '';

    // 3. Istighfar: simpan nilainya langsung
    $istighfar_val = (int)($_POST['istighfar'] ?? 0);
    $dataAmalan[$bulan]['istighfar'][$hari] = $istighfar_val > 0 ? $istighfar_val : '';


    simpanDataAmalan($dataFile, $dataAmalan);
    $pesan_sukses = "Data untuk tanggal " . date('d F Y', strtotime($tanggal)) . " berhasil disimpan!";
}

// --- PERSIAPAN DATA UNTUK DITAMPILKAN ---
$bulan_sekarang = date('Y-m');
$semuaDataAmalan = bacaDataAmalan($dataFile);
$dataBulanIni = $semuaDataAmalan[$bulan_sekarang] ?? [];
$jumlah_hari = date('t');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracker Amalan Yaumiyah</title>
    <?php
// Cek apakah variabel $manifestPath didefinisikan (menandakan kita di subfolder).
if (isset($manifestPath)) {
    // Jika di subfolder, HANYA cetak link manifest jika path-nya tidak kosong.
    if (!empty($manifestPath)) {
        echo '<link rel="manifest" href="' . htmlspecialchars($manifestPath) . '">';
    }
    // Jika $manifestPath kosong atau tidak ada, maka subfolder tidak akan punya manifest.
} else {
    // Jika kita TIDAK di subfolder (di folder induk), gunakan manifest default.
    echo '<link rel="manifest" href="https://krasyid822.github.io/Drive/manifest.json">';
}
?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Flex:opsz,wght@8..144,300;8..144,400;8..144,500;8..144,600;8..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

    <style>
        /* --- MATERIAL YOU DESIGN SYSTEM --- */
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        
        /* --- PALET WARNA MATERIAL YOU --- */
        :root {
            /* Primary Colors - Material You Dynamic Color */
            --md-sys-color-primary: #006A6A;
            --md-sys-color-on-primary: #FFFFFF;
            --md-sys-color-primary-container: #6FF7F7;
            --md-sys-color-on-primary-container: #002020;
            
            /* Secondary Colors */
            --md-sys-color-secondary: #4A6363;
            --md-sys-color-on-secondary: #FFFFFF;
            --md-sys-color-secondary-container: #CCE8E7;
            --md-sys-color-on-secondary-container: #051F1F;
            
            /* Tertiary Colors */
            --md-sys-color-tertiary: #4B607C;
            --md-sys-color-on-tertiary: #FFFFFF;
            --md-sys-color-tertiary-container: #D3E4FF;
            --md-sys-color-on-tertiary-container: #041C35;
            
            /* Error Colors */
            --md-sys-color-error: #BA1A1A;
            --md-sys-color-on-error: #FFFFFF;
            --md-sys-color-error-container: #FFDAD6;
            --md-sys-color-on-error-container: #410002;
            
            /* Surface Colors */
            --md-sys-color-surface: #FAFDFC;
            --md-sys-color-on-surface: #191C1C;
            --md-sys-color-surface-variant: #DAE5E3;
            --md-sys-color-on-surface-variant: #3F4948;
            --md-sys-color-outline: #6F7978;
            --md-sys-color-outline-variant: #BEC9C7;
            
            /* Background */
            --md-sys-color-background: #F4FBF9;
            --md-sys-color-on-background: #191C1C;
            
            /* Surface Containers */
            --md-sys-color-surface-container-lowest: #FFFFFF;
            --md-sys-color-surface-container-low: #F0F7F6;
            --md-sys-color-surface-container: #EAF1F0;
            --md-sys-color-surface-container-high: #E4EBEA;
            --md-sys-color-surface-container-highest: #DEE5E4;
            
            /* Elevation & Shadows */
            --md-sys-elevation-level0: none;
            --md-sys-elevation-level1: 0px 1px 2px 0px rgba(0, 0, 0, 0.3), 0px 1px 3px 1px rgba(0, 0, 0, 0.15);
            --md-sys-elevation-level2: 0px 1px 2px 0px rgba(0, 0, 0, 0.3), 0px 2px 6px 2px rgba(0, 0, 0, 0.15);
            --md-sys-elevation-level3: 0px 4px 8px 3px rgba(0, 0, 0, 0.15), 0px 1px 3px 0px rgba(0, 0, 0, 0.3);
            --md-sys-elevation-level4: 0px 6px 10px 4px rgba(0, 0, 0, 0.15), 0px 2px 3px 0px rgba(0, 0, 0, 0.3);
            --md-sys-elevation-level5: 0px 8px 12px 6px rgba(0, 0, 0, 0.15), 0px 4px 4px 0px rgba(0, 0, 0, 0.3);
            
            /* Status Colors (Material You adapted) */
            --color-success: #146C2E;
            --color-success-container: #A8F5B8;
            --color-on-success-container: #002106;
            
            --color-warning: #7A5900;
            --color-warning-container: #FFDF9E;
            --color-on-warning-container: #261900;
            
            --color-info: #00658E;
            --color-info-container: #C2E7FF;
            --color-on-info-container: #001E2E;
            
            /* Shape */
            --md-sys-shape-corner-none: 0px;
            --md-sys-shape-corner-extra-small: 4px;
            --md-sys-shape-corner-small: 8px;
            --md-sys-shape-corner-medium: 12px;
            --md-sys-shape-corner-large: 16px;
            --md-sys-shape-corner-extra-large: 28px;
            --md-sys-shape-corner-full: 9999px;
            
            /* Typography */
            --font-family: 'Roboto Flex', -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
            
            /* Legacy compatibility */
            --color-primary: var(--md-sys-color-primary);
            --color-primary-dark: #004D4D;
            --color-secondary: var(--md-sys-color-secondary-container);
            --color-background: var(--md-sys-color-background);
            --color-surface: var(--md-sys-color-surface);
            --color-text: var(--md-sys-color-on-surface);
            --color-text-light: var(--md-sys-color-on-surface-variant);
            --color-border: var(--md-sys-color-outline-variant);

            /* Status Colors (lebih lembut) */
            --color-good: var(--color-success-container); 
            --color-good-text: var(--color-success);
            --color-ok-2: var(--color-info-container); 
            --color-ok-2-text: var(--color-info);
            --color-ok-1: var(--color-warning-container); 
            --color-ok-1-text: var(--color-warning);
            --color-qadha: var(--md-sys-color-error-container); 
            --color-qadha-text: var(--md-sys-color-error);
            --color-empty: var(--md-sys-color-surface-container); 
            --color-empty-text: var(--md-sys-color-on-surface-variant);
        }

        /* --- GLOBAL & BODY --- */
        html { scroll-behavior: smooth; }
        body {
            font-family: var(--font-family);
            margin: 0;
            background-color: var(--color-background);
            color: var(--color-text);
            line-height: 1.6;
            padding-bottom: 120px;
            position: relative;
            transition: background 1s ease;
            font-weight: 500;
        }
        
        /* --- SIMPLE SOLID BACKGROUND (SKEUOMORPHISM) --- */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 1;
            transition: background 0.5s ease;
        }
        
        /* Solid colors untuk waktu berbeda */
        body.time-dawn::before { background: #3a5b8a; }
        body.time-subuh::before { background: #87CEEB; }
        body.time-morning::before { background: #B2DFEE; }
        body.time-noon::before { background: #E1F5FE; }
        body.time-asr::before { background: #FFE5B4; }
        body.time-maghrib::before { background: #FFB47B; }
        body.time-night::before { background: #2C3E50; }

        /* --- CONTAINER & LAYOUT --- */
        .container {
            /* max-width: 1300px; */
            margin: 30px auto;
            padding: 20px 32px;
            position: relative;
            z-index: 1;
        }
        .main-header {
            text-align: center;
            margin-bottom: 48px;
            padding: 32px 24px;
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            border: 1px solid #d0d0d0;
            position: relative;
        }
        .main-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--md-sys-color-on-background);
            letter-spacing: -0.5px;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1;
        }
        .main-header p {
            font-size: 1rem;
            color: var(--md-sys-color-on-surface-variant);
            margin-top: 8px;
            margin-bottom: 0;
            position: relative;
            z-index: 1;
        }
        
        /* --- STYLE UNTUK NAMA PENGGUNA (Material You Chip) --- */
        .nama-pengguna {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(180deg, #D6EFED 0%, #CCE8E7 100%);
            color: var(--md-sys-color-on-secondary-container);
            padding: 8px 20px;
            border-radius: 6px;
            margin-top: 16px;
            font-size: 0.875rem;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
            transition: all 0.2s ease;
            border: 1px solid #B0D0CE;
        }
        .nama-pengguna:hover {
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }

        /* --- FORM CONTAINER --- */
        .form-container {
            background: #FAFAFA;
            padding: 32px;
            border-radius: 12px;
            margin-bottom: 40px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            border: 1px solid #d0d0d0;
            position: relative;
        }
        h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
            text-align: left;
            color: var(--md-sys-color-on-surface);
            position: relative;
            z-index: 1;
        }
        
        /* --- QUICK NAVIGATION --- */
        .quick-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 32px;
            padding-bottom: 28px;
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
        }
        .nav-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 6px;
            background: var(--md-sys-color-primary);
            color: #FFFFFF;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9375rem;
            border: 1px solid #004D4D;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }
        .nav-button i { font-size: 1.2em; }
        .nav-button:hover {
            background: #008585;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        .nav-button:active {
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), inset 0 -1px 2px rgba(0, 0, 0, 0.1);
            transform: translateY(0);
        }

        /* --- FORM ELEMENTS (Material Design 3 Filled Style) --- */
        fieldset {
            border: none;
            padding: 0;
            margin: 0;
            margin-bottom: 36px;
        }
        fieldset:last-of-type { margin-bottom: 0; }

        legend {
            font-weight: 700;
            color: var(--md-sys-color-primary);
            padding-bottom: 16px;
            margin-bottom: 20px;
            font-size: 1.375rem;
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
        }
        .form-group { margin-bottom: 24px; }
        label { 
            font-weight: 600; 
            font-size: 0.875rem; 
            margin-bottom: 8px; 
            display: block;
            color: var(--md-sys-color-on-surface-variant);
        }
        
        /* Material Design 3 Filled Text Fields */
        input[type="date"], 
        input[type="text"], 
        input[type="number"], 
        input[type="time"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #bbb;
            border-radius: 4px;
            box-sizing: border-box;
            background: #FFFFFF;
            transition: all 0.2s ease;
            font-family: var(--font-family);
            font-size: 1rem;
            color: var(--md-sys-color-on-surface);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        input:hover, select:hover {
            border-color: #999;
        }
        input:focus, select:focus {
            outline: none;
            border: 2px solid var(--md-sys-color-primary);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05), 0 0 0 3px rgba(0, 106, 106, 0.1);
        }
        
        /* --- PRAYER CYCLE BUTTON & SUN EFFECT (Material You Cards) --- */
        .prayer-inputs-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        .prayer-input-group {
            display: flex;
            flex-direction: column;
            padding: 20px;
            border-radius: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border: 1px solid #ccc;
        }
        .prayer-input-group:hover {
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15);
        }
        .prayer-input-group label {
            font-weight: 900;
            font-size: 1.75rem;
            margin-bottom: 16px;
            color: white;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.6);
            letter-spacing: 1.5px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.4);
            transition: none; /* Hilangkan animasi pada label waktu sholat */
        }
        .prayer-input-group label::before {
            content: '🕌';
            margin-right: 12px;
            font-size: 1.5rem;
            filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.5));
        }
        /* Icon khusus untuk setiap waktu sholat */
        .prayer-time-subuh label::before {
            content: '🌅';
        }
        .prayer-time-dzuhur label::before {
            content: '☀️';
        }
        .prayer-time-ashar label::before {
            content: '🌞';
        }
        .prayer-time-maghrib label::before {
            content: '🌇';
        }
        .prayer-time-isya label::before {
            content: '☪️';
        }
        /* Nonaktifkan efek hover untuk semua perangkat */
        .prayer-input-group:hover label {
            transform: none;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.6);
            background: rgba(0, 0, 0, 0.3);
        }
        /* Sun Position Solid Colors with texture */
        .prayer-time-subuh { 
            background: linear-gradient(180deg, #6B8AAA 0%, #5A7B9A 100%);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        .prayer-time-dzuhur { 
            background: linear-gradient(180deg, #A7DEEB 0%, #87CEEB 100%);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        .prayer-time-ashar { 
            background: linear-gradient(180deg, #6692C4 0%, #4682B4 100%);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        .prayer-time-maghrib { 
            background: linear-gradient(180deg, #FF9E7F 0%, #FF8E6F 100%);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        .prayer-time-isya { 
            background: linear-gradient(180deg, #3C5364 0%, #2C4354 100%);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }
        
        /* --- PRAYER SLIDER STYLE (VERTICAL) --- */
        .prayer-slider-wrapper {
            position: relative;
            display: flex;
            align-items: stretch;
            gap: 16px;
            padding: 16px;
            background: #FFFFFF;
            border-radius: 8px;
            margin-bottom: 8px;
            min-height: 320px;
            border: 1px solid #ddd;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        /* Slider container for proper positioning */
        .prayer-slider-container {
            display: flex;
            align-items: center;
        }
        
        .prayer-slider {
            writing-mode: vertical-lr;
            direction: rtl;
            width: 8px;
            height: 280px;
            margin: 0;
            padding: 0;
            outline: none;
            transition: opacity 0.2s;
            cursor: pointer;
            background: linear-gradient(to top, 
                #d0d0d0 0%, 
                #d0d0d0 20%, 
                #BA1A1A 20%, 
                #BA1A1A 40%, 
                #7A5900 40%, 
                #7A5900 60%,
                #00658E 60%,
                #00658E 80%,
                #146C2E 80%,
                #146C2E 100%);
            border-radius: 4px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3), 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        
        /* Reset default appearance for custom styling */
        .prayer-slider::-webkit-slider-runnable-track {
            background: transparent;
        }
        
        .prayer-slider::-moz-range-track {
            background: transparent;
        }
        
        .prayer-slider:hover {
            opacity: 0.8;
        }
        
        /* Slider Thumb - Chrome/Safari */
        .prayer-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            background: white;
            border: 3px solid var(--md-sys-color-primary);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }
        
        .prayer-slider::-webkit-slider-thumb:hover {
            transform: scale(1.2);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        
        /* Slider Thumb - Firefox */
        .prayer-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            background: white;
            border: 3px solid var(--md-sys-color-primary);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }
        
        .prayer-slider::-moz-range-thumb:hover {
            transform: scale(1.2);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        
        /* Slider Labels (Vertical Layout) */
        .slider-labels {
            display: flex;
            flex-direction: column-reverse;
            justify-content: space-between;
            gap: 8px;
            height: 280px;
            padding: 0;
        }
        
        .slider-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--md-sys-color-on-surface);
            text-align: left;
            min-width: 90px;
            transition: all 0.2s;
            opacity: 0.7;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: var(--md-sys-shape-corner-small);
            letter-spacing: 0.5px;
        }
        
        .slider-label:hover {
            background-color: rgba(0, 0, 0, 0.08);
            opacity: 1;
            transform: translateX(2px);
        }
        
        .slider-label.active {
            color: white;
            background-color: var(--md-sys-color-primary);
            font-weight: 900;
            opacity: 1;
            transform: translateX(4px) scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Slider Indicator */
        .slider-indicator {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 6px;
            flex: 1;
            padding: 14px 16px;
            border-radius: 6px;
            background: linear-gradient(180deg, #f8f8f8 0%, #e8e8e8 100%);
            color: var(--md-sys-color-on-surface);
            font-size: 0.95rem;
            font-weight: 700;
            transition: all 0.2s ease;
            min-height: 140px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            border: 1px solid #ccc;
            cursor: pointer;
            user-select: none;
            position: relative;
        }

        /* Compact mode when a state is selected (no virtue text shown) */
        .slider-indicator.compact {
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .slider-indicator.compact .status-icon {
            font-size: 2rem;
            margin-bottom: 8px;
        }
        .slider-indicator.compact span {
            font-size: 1.35rem;
            line-height: 1.3;
        }
        
        /* Hint badge untuk double-click */
        .slider-indicator::after {
            content: '2×';
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0, 106, 106, 0.2);
            color: var(--md-sys-color-primary);
            font-size: 0.625rem;
            font-weight: 900;
            padding: 3px 6px;
            border-radius: var(--md-sys-shape-corner-small);
            opacity: 0.6;
            transition: all 0.2s;
            pointer-events: none;
        }
        
        .slider-indicator:hover {
            box-shadow: var(--md-sys-elevation-level2);
            transform: scale(1.02);
        }
        
        .slider-indicator:hover::after {
            opacity: 1;
            background: rgba(0, 106, 106, 0.3);
        }
        
        .slider-indicator:active {
            transform: scale(0.98);
        }
        
        /* Touch-specific styles */
        @media (hover: none) and (pointer: coarse) {
            /* For touch devices */
            .slider-indicator {
                -webkit-tap-highlight-color: transparent;
                touch-action: manipulation;
            }
            
            .slider-indicator::after {
                opacity: 0.8;
                content: '👆2×';
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            .slider-indicator:active {
                transform: scale(0.95);
                transition: transform 0.1s;
            }
        }
        
        .slider-indicator .status-icon {
            font-size: 1.75rem;
            margin-bottom: 2px;
        }
        
        .slider-indicator span {
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        
        /* Prayer virtue text */
        .prayer-virtue {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1.4;
            margin-top: 8px;
            opacity: 0.85;
            font-style: italic;
            letter-spacing: 0.2px;
        }
        
        .slider-indicator.status-M {
            background: var(--color-good);
            color: var(--color-good-text);
        }
        
        .slider-indicator.status-R-J {
            background: var(--color-ok-2);
            color: var(--color-ok-2-text);
        }
        
        .slider-indicator.status-M-S,
        .slider-indicator.status-R {
            background: var(--color-ok-1);
            color: var(--color-ok-1-text);
        }
        
        .slider-indicator.status-Q {
            background: var(--color-qadha);
            color: var(--color-qadha-text);
        }
        
        .prayer-cycle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            border-radius: 6px;
            border: 1px solid #bbb;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            background: linear-gradient(180deg, #f5f5f5 0%, #e0e0e0 100%);
            color: var(--md-sys-color-on-surface);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .prayer-cycle-btn:hover {
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .prayer-cycle-btn:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
            transform: translateY(1px);
        }
        .prayer-cycle-btn i { font-size: 1.1em; }
        .prayer-cycle-btn.status-M { 
            border-color: var(--color-good-text); 
            color: var(--color-good-text);
            background-color: var(--color-good);
        }
        .prayer-cycle-btn.status-R-J { 
            border-color: var(--color-ok-2-text); 
            color: var(--color-ok-2-text);
            background-color: var(--color-ok-2);
        }
        .prayer-cycle-btn.status-M-S, 
        .prayer-cycle-btn.status-R { 
            border-color: var(--color-ok-1-text); 
            color: var(--color-ok-1-text);
            background-color: var(--color-ok-1);
        }
        .prayer-cycle-btn.status-Q { 
            border-color: var(--color-qadha-text); 
            color: var(--color-qadha-text);
            background-color: var(--color-qadha);
        }
        .prayer-cycle-btn.status-empty { 
            border-color: var(--md-sys-color-outline-variant); 
            color: var(--md-sys-color-on-surface-variant); 
            font-weight: 500;
        }

        /* --- CUSTOM CHECKBOX & NEW DETAIL INPUT (Material You Style) --- */
        .checkbox-group { display: flex; align-items: center; flex-wrap: nowrap; gap: 8px; }
        .checkbox-group label { 
            margin-bottom: 0; 
            font-weight: 500; 
            cursor: pointer;
            color: var(--md-sys-color-on-surface);
            white-space: nowrap;
        }
        .checkbox-group input[type="checkbox"] { 
            margin-right: 8px; 
            width: 20px; 
            height: 20px; 
            accent-color: var(--md-sys-color-primary); 
            cursor: pointer; 
        }
        .optional-input-wrapper {
            margin-top: 12px;
            transition: all 0.3s cubic-bezier(0.2, 0, 0, 1);
        }
        .info-btn {
            background: linear-gradient(180deg, #f0f0f0 0%, #e0e0e0 100%);
            border: 1px solid #bbb;
            color: var(--md-sys-color-primary);
            cursor: pointer;
            padding: 4px 8px;
            font-size: 1.25rem;
            transition: all 0.2s ease;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .info-btn:hover { 
            background: linear-gradient(180deg, #ffffff 0%, #e8e8e8 100%);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .info-btn:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
            transform: translateY(1px);
        }
        
        .tilawah-grid { display: grid; gap: 12px; grid-template-columns: 1fr 90px 90px; }
        .rawatib-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .form-group-columns { display: grid; gap: 20px; grid-template-columns: 1fr 1fr;}

        /* --- BUTTONS (Material You Filled & Tonal) --- */
        .submit-btn {
            display: block; 
            width: 100%; 
            padding: 16px; 
            background: linear-gradient(180deg, #008A8A 0%, #006A6A 100%);
            color: #FFFFFF;
            border: 1px solid #004D4D;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            letter-spacing: 0.1px;
            text-transform: uppercase;
        }
        .submit-btn:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.3);
            transform: translateY(2px);
        }
        
        /* Submit button berubah warna saat ada perubahan */
        body.show-floating-save .submit-btn {
            background: #4CAF50;
            color: #ffffff;
            font-weight: 800;
            box-shadow: 0 3px 8px rgba(76, 175, 80, 0.4);
        }
        
        body.show-floating-save .submit-btn:hover {
            background: #388E3C;
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.5);
            transform: translateY(-1px);
        }
        
        .submit-btn:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            background: linear-gradient(180deg, #009999 0%, #007777 100%);
        }
        .original-submit-wrapper {
            margin-top: 24px;
            transition: opacity 0.3s, visibility 0.3s;
        }
        .download-btn {
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            text-decoration: none; 
            background: linear-gradient(180deg, #5B7FA0 0%, #4B607C 100%);
            color: #FFFFFF;
            padding: 12px 24px;
            border-radius: 8px;
            margin-top: 20px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            border: 1px solid #3A4F6A;
            font-size: 0.875rem;
        }
        .download-btn:hover { 
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.2);
            background: linear-gradient(180deg, #6B8FB0 0%, #5B7FA0 100%);
        }
        .download-btn:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.3);
            transform: translateY(2px);
        }
        .download-btn.pdf-btn {
            background: linear-gradient(180deg, #7B5B9F 0%, #684B88 100%);
            border-color: #513A6B;
        }
        .download-btn.pdf-btn:hover {
            background: linear-gradient(180deg, #8A6AAE 0%, #775A9D 100%);
        }
        .download-actions {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }
        
        /* --- FLOATING SAVE BUTTON (Material You Scrim & Surface) --- */
        .floating-save-container {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #4CAF50;
            padding: 20px 0;
            box-shadow: 0 -3px 10px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            transform: translateY(150%);
            transition: transform 0.3s ease;
            border-top: 2px solid #388E3C;
            border-bottom: none;
        }
        
        /* Floating save di atas (ketika submit button di bawah scroll) */
        .floating-save-container.position-top {
            bottom: auto;
            top: 0;
            transform: translateY(-150%);
            border-top: none;
            border-bottom: 2px solid #388E3C;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        

        

        .floating-save-container .container {
            margin: 0 auto;
            padding-top: 0;
            padding-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        .floating-save-container p {
            margin: 0;
            font-weight: 700;
            font-size: 1.125rem;
            color: #ffffff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .floating-save-container p i {
            font-size: 1.5rem;
            animation: shake 0.5s ease-in-out infinite;
        }
        

        
        /* Gunakan style submit-btn yang sama untuk floating dan original */
        body.show-floating-save .floating-save-container {
            transform: translateY(0);
        }
        
        body.show-floating-save .floating-save-container.position-top {
            transform: translateY(0);
        }
        
        body.show-floating-save .original-submit-wrapper {
            opacity: 0;
            visibility: hidden;
        }
        
        /* Submit button visibility */
        body.submit-visible .original-submit-wrapper {
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        /* Hide floating save when original submit is visible (fallback) */
        body.submit-visible .floating-save-container:not(.position-top) {
            transform: translateY(150%) !important;
        }
        
        body.submit-visible .floating-save-container.position-top {
            transform: translateY(-150%) !important;
        }
        
        /* --- MODAL FOR AYYAMUL BIDH INFO (Material You Dialog) --- */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background: #FFFFFF;
            padding: 32px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            position: relative;
            transform: scale(0.9);
            transition: all 0.2s ease;
            border: 1px solid #ccc;
        }
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        .modal-close-btn {
            position: absolute;
            top: 12px; right: 12px;
            background: linear-gradient(180deg, #f5f5f5 0%, #e0e0e0 100%);
            border: 1px solid #bbb;
            font-size: 1.5rem;
            color: #333;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: all 0.2s ease;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .modal-close-btn:hover {
            background: linear-gradient(180deg, #ffffff 0%, #e8e8e8 100%);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        }
        .modal-close-btn:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
            transform: translateY(1px);
        }
        .modal-content h3 { 
            margin-top: 0; 
            color: var(--md-sys-color-primary);
            font-weight: 700;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }
        .modal-content p { 
            color: var(--md-sys-color-on-surface-variant); 
            font-style: italic;
            position: relative;
            z-index: 1;
        }
        .modal-content .disclaimer { 
            font-size: 0.8rem; 
            font-style: normal; 
            margin-top:16px; 
            color: var(--md-sys-color-on-surface-variant);
            opacity: 0.7;
            position: relative;
            z-index: 1;
        }
        .modal-content h4 { 
            margin-bottom: 12px;
            font-weight: 500;
            color: var(--md-sys-color-on-surface);
            position: relative;
            z-index: 1;
        }
        .modal-content ul { 
            padding-left: 20px; 
            margin: 0;
            position: relative;
            z-index: 1;
        }
        .modal-content li { 
            margin-bottom: 8px;
            color: var(--md-sys-color-on-surface);
            position: relative;
            z-index: 1;
        }

        /* --- TOAST NOTIFICATION (Material You Snackbar) --- */
        #toast-notification {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translate(-50%, 150%);
            background: linear-gradient(180deg, #3A3A3A 0%, #2A2A2A 100%);
            color: #F4EFF4;
            padding: 14px 24px;
            border-radius: 6px;
            z-index: 9999;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid #1A1A1A;
            transition: transform 0.3s ease, opacity 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.875rem;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        #toast-notification.show {
            transform: translate(-50%, 0);
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        #toast-notification.success {
            background: var(--color-success);
            color: var(--md-sys-color-on-primary);
        }
        #toast-notification.error {
            background: var(--md-sys-color-error);
            color: var(--md-sys-color-on-error);
        }


        /* --- PESAN & NOTIFIKASI (Material You Alerts) --- */
        .pesan {
            padding: 16px 20px;
            border-radius: 6px;
            text-align: center;
            margin: 24px 0;
            border: 1px solid rgba(0, 0, 0, 0.1);
            font-size: 0.875rem;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        .pesan-sukses { 
            background-color: var(--color-success-container);
            color: var(--color-on-success-container);
        }
        .pesan-pengingat {
            background-color: var(--color-warning-container);
            color: var(--color-on-warning-container);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .pesan-pengingat strong { font-size: 1.1rem; }

        /* --- LAPORAN TABLE (Material You Data Table) --- */
        .table-container {
            background: #FAFAFA;
            padding: 32px;
            border-radius: 12px;
            margin-top: 40px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
            border: 1px solid #d0d0d0;
            position: relative;
        }
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            position: relative;
            z-index: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: var(--md-sys-shape-corner-medium);
            overflow: hidden;
        }
        th, td { 
            border: 1px solid var(--md-sys-color-outline-variant);
            padding: 12px 10px;
            text-align: center;
            min-width: 50px;
            font-size: 0.875rem;
        }
        th {
            background: linear-gradient(180deg, #E8E8E8 0%, #D0D0D0 100%);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #333;
            position: sticky;
            top: -1px;
            z-index: 10;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5), 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        td { transition: background-color 0.2s cubic-bezier(0.2, 0, 0, 1); }
        tr:not(.kategori-row):hover td { 
            background-color: var(--md-sys-color-surface-container) !important;
        }

        .th-ibadah, .td-ibadah { 
            text-align: left;
            min-width: 130px;
            font-weight: 600;
        }
        td.td-ibadah { vertical-align: top; }
        .td-ibadah small { 
            color: var(--md-sys-color-on-surface-variant);
            font-size: 0.75rem;
            font-weight: 500;
        }
        .kategori-utama { 
            font-weight: 700;
            background-color: var(--md-sys-color-surface-container-high);
        }
        .td-kategori-header { vertical-align: middle; }

        /* --- TABLE CELL STATUS COLORS --- */
        .status-good { background-color: var(--color-good); color: var(--color-good-text); font-weight: 600; }
        .status-ok-2 { background-color: var(--color-ok-2); color: var(--color-ok-2-text); }
        .status-ok-1 { background-color: var(--color-ok-1); color: var(--color-ok-1-text); }
        .status-qadha { background-color: var(--color-qadha); color: var(--color-qadha-text); }
        .status-empty { background-color: var(--color-surface); }

        /* --- PANEL PENJELASAN (KARTU FITUR - Material You Cards) --- */
        #penjelasan-fitur {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 40px;
            padding: 0;
            background: none;
            border: none;
        }
        .feature-card {
            background: #FFFFFF;
            padding: 24px;
            border-radius: 8px;
            border: 1px solid #d0d0d0;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .feature-card:hover {
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
            border-color: var(--md-sys-color-primary);
            transform: translateY(-1px);
        }
        .feature-card h3 {
            font-size: 1rem;
            font-weight: 500;
            margin-top: 0;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--md-sys-color-primary);
            position: relative;
            z-index: 1;
        }
        .feature-card p, .feature-card ul { 
            color: var(--md-sys-color-on-surface-variant);
            font-size: 0.875rem;
            position: relative;
            z-index: 1;
        }
        .feature-card ul { padding-left: 20px; margin: 0; }
        .feature-card li { 
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        .feature-card li strong { color: var(--md-sys-color-on-surface); }
        
        /* --- ISTIGHFAR SLIDER (Vertical Style) --- */
        .istighfar-group {
            display: flex;
            align-items: stretch;
            gap: 16px;
            margin-top: 12px;
            background: #FFFFFF;
            border-radius: 8px;
            padding: 16px;
            min-height: 280px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #ddd;
        }
        
        #istighfar_slider { 
            writing-mode: vertical-lr;
            direction: rtl;
            width: 8px;
            height: 250px;
            margin: 0;
            padding: 0;
            outline: none;
            transition: opacity 0.2s;
            cursor: pointer;
            background: linear-gradient(to top, 
                #d0d0d0 0%, 
                #d0d0d0 10%, 
                #4CAF50 10%, 
                #4CAF50 50%,
                #2196F3 50%,
                #2196F3 75%,
                #FF9800 75%,
                #FF9800 100%);
            border-radius: 4px;
            accent-color: var(--md-sys-color-primary);
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3), 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        
        #istighfar_slider::-webkit-slider-runnable-track {
            background: transparent;
        }
        
        #istighfar_slider::-moz-range-track {
            background: transparent;
        }
        
        #istighfar_slider:hover {
            opacity: 0.8;
        }
        
        #istighfar_slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            background: white;
            border: 3px solid var(--md-sys-color-primary);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }
        
        #istighfar_slider::-webkit-slider-thumb:hover {
            transform: scale(1.2);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        
        #istighfar_slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            background: white;
            border: 3px solid var(--md-sys-color-primary);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }
        
        #istighfar_slider::-moz-range-thumb:hover {
            transform: scale(1.2);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        
        .istighfar-labels {
            display: flex;
            flex-direction: column-reverse;
            justify-content: space-between;
            height: 250px;
            gap: 4px;
            padding: 0;
        }
        
        .istighfar-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--md-sys-color-on-surface);
            text-align: left;
            min-width: 80px;
            transition: all 0.2s;
            opacity: 0.7;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: var(--md-sys-shape-corner-small);
            letter-spacing: 0.5px;
        }
        
        .istighfar-label:hover {
            background-color: rgba(0, 0, 0, 0.08);
            opacity: 1;
            transform: translateX(2px);
        }
        
        .istighfar-label.active {
            color: white;
            background-color: var(--md-sys-color-primary);
            font-weight: 900;
            opacity: 1;
            transform: translateX(4px) scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        #istighfar_value {
            font-weight: 700;
            color: var(--md-sys-color-on-primary-container);
            background: linear-gradient(180deg, #C2E7FF 0%, #9DD4F5 100%);
            padding: 14px 16px;
            border-radius: 6px;
            min-width: 100px;
            text-align: center;
            font-size: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            border: 1px solid #85C1E2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            flex: 1;
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: all 0.2s ease;
        }
        
        #istighfar_value:hover {
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transform: scale(1.01);
        }
        
        #istighfar_value:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
            transform: scale(0.99);
        }
        
        #istighfar_value:active {
            transform: scale(0.98);
        }
        
        /* Hint badge untuk double-click/tap pada istighfar */
        #istighfar_value::before {
            content: '2×';
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0, 106, 106, 0.2);
            color: var(--md-sys-color-primary);
            font-size: 0.625rem;
            font-weight: 900;
            padding: 3px 6px;
            border-radius: var(--md-sys-shape-corner-small);
            opacity: 0.6;
            transition: all 0.2s;
            pointer-events: none;
        }
        
        #istighfar_value:hover::before {
            opacity: 1;
            background: rgba(0, 106, 106, 0.3);
        }
        
        /* Touch-specific styles for istighfar */
        @media (hover: none) and (pointer: coarse) {
            #istighfar_value {
                -webkit-tap-highlight-color: transparent;
                touch-action: manipulation;
            }
            
            #istighfar_value::before {
                opacity: 0.8;
                content: '👆2×';
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            
            #istighfar_value:active {
                transform: scale(0.95);
                transition: transform 0.1s;
            }
        }
        
        #istighfar_value::after {
            content: 'kali';
            font-size: 0.75rem;
            font-weight: 600;
            opacity: 0.8;
        }
        
        /* --- RESPONSIVE DESIGN (Material You Adaptive Layouts) --- */
        @media (max-width: 768px) {
            .istighfar-group {
                gap: 10px;
                padding: 12px;
                min-height: 200px;
            }
            
            #istighfar_slider {
                height: 180px;
            }
            
            .istighfar-labels {
                height: 180px;
                gap: 2px;
            }
            
            .istighfar-label {
                font-size: 0.75rem;
                min-width: 70px;
                padding: 4px 8px;
            }
            
            #istighfar_value {
                font-size: 1.25rem;
                padding: 10px 12px;
                min-width: 80px;
            }
        }
        
        @media (max-width: 480px) {
            .istighfar-group {
                gap: 8px;
                padding: 10px;
                min-height: 160px;
            }
            
            #istighfar_slider {
                height: 150px;
                width: 6px;
            }
            
            .istighfar-labels {
                height: 150px;
            }
            
            .istighfar-label {
                font-size: 0.6875rem;
                min-width: 60px;
                padding: 3px 6px;
            }
            
            #istighfar_value {
                font-size: 1.125rem;
                padding: 8px 10px;
                min-width: 70px;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 12px 16px;
                margin: 16px auto;
            }
            
            .main-header {
                padding: 16px 20px;
            }
            
            .main-header h1 {
                font-size: 1.75rem;
            }
            
            .main-header p {
                font-size: 0.8125rem;
            }
            
            .form-container,
            .table-container {
                padding: 20px 16px;
                border-radius: var(--md-sys-shape-corner-large);
            }
            
            h2 {
                font-size: 1.375rem;
                margin-bottom: 20px;
            }
            
            legend {
                font-size: 1.125rem;
                padding-bottom: 12px;
                margin-bottom: 16px;
            }
            
            .quick-nav {
                gap: 6px;
            }
            
            .nav-button {
                padding: 6px 10px;
                font-size: 0.75rem;
            }
            
            /* Optimize Prayer Input Group for Mobile */
            .prayer-input-group {
                padding: 12px;
            }
            
            .prayer-input-group label {
                font-size: 1.25rem;
                margin-bottom: 10px;
                padding: 6px 12px;
                letter-spacing: 1px;
            }
            
            .prayer-input-group label::before {
                font-size: 1.125rem;
                margin-right: 8px;
            }
            
            .prayer-slider-wrapper {
                gap: 10px;
                padding: 12px;
                min-height: 240px;
            }
            
            .prayer-slider {
                height: 200px;
            }
            
            .slider-labels {
                height: 200px;
                gap: 4px;
            }
            
            .slider-label {
                font-size: 0.75rem;
                min-width: 70px;
                padding: 4px 8px;
            }
            
            .slider-indicator {
                font-size: 0.8125rem;
                padding: 10px 12px;
                min-height: 110px;
            }
            
            .slider-indicator .status-icon {
                font-size: 1.5rem;
            }
            
            .prayer-virtue {
                font-size: 0.6875rem;
                line-height: 1.3;
                margin-top: 6px;
            }
            
            .prayer-inputs-container {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .form-group-columns {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .tilawah-grid {
                grid-template-columns: 1fr;
            }
            
            .floating-save-container .container {
                flex-direction: column;
                gap: 12px;
                padding: 0 16px;
            }
            
            .floating-save-container p {
                font-size: 1rem;
                text-align: center;
            }
            
            .floating-save-container .submit-btn {
                width: 100%;
                min-width: auto;
                padding: 16px 24px;
                font-size: 0.9375rem;
            }
            
            #penjelasan-fitur {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding-bottom: 120px;
            }
            
            .container {
                padding: 10px 12px;
                margin: 12px auto;
            }
            
            .main-header {
                padding: 12px 16px;
            }
            
            .main-header h1 {
                font-size: 1.5rem;
            }
            
            .main-header p {
                font-size: 0.75rem;
            }
            
            .form-container,
            .table-container {
                padding: 16px 12px;
            }
            
            h2 {
                font-size: 1.25rem;
                margin-bottom: 16px;
            }
            
            legend {
                font-size: 1rem;
                padding-bottom: 10px;
                margin-bottom: 12px;
            }
            
            /* Extra compact Prayer Input for small screens */
            .prayer-input-group {
                padding: 10px;
            }
            
            .prayer-input-group label {
                font-size: 1rem;
                margin-bottom: 8px;
                padding: 5px 10px;
                letter-spacing: 0.8px;
            }
            
            .prayer-input-group label::before {
                font-size: 0.9rem;
                margin-right: 6px;
            }
            
            .prayer-slider-wrapper {
                gap: 8px;
                padding: 10px;
                min-height: 200px;
            }
            
            .prayer-slider {
                height: 170px;
                width: 6px;
            }
            
            .slider-labels {
                height: 170px;
                gap: 2px;
            }
            
            .slider-label {
                font-size: 0.6875rem;
                min-width: 60px;
                padding: 3px 6px;
            }
            
            .slider-indicator {
                font-size: 0.75rem;
                padding: 8px 10px;
                min-height: 100px;
                gap: 4px;
            }
            
            .slider-indicator .status-icon {
                font-size: 1.25rem;
            }
            
            .prayer-virtue {
                font-size: 0.625rem;
                line-height: 1.25;
                margin-top: 5px;
            }
            
            .rawatib-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-nav {
                gap: 4px;
            }
            
            .nav-button {
                padding: 5px 8px;
                font-size: 0.6875rem;
            }
            
            .floating-save-container .container {
                padding: 0 10px;
            }
            
            .floating-save-container p {
                font-size: 0.9375rem;
            }
            
            .floating-save-container .submit-btn {
                padding: 14px 20px;
                font-size: 0.875rem;
            }
        }

    </style>
</head>
<body>
    <?php include __DIR__ . '/shortcuts.php'; ?>

<div id="toast-notification"></div>

<div class="container">
    <header class="main-header">
        <h1>Tracker Amalan Yaumiyah</h1>
        <p>Catat dan pantau ibadah harianmu untuk menjadi lebih istiqomah.</p>
        
        <?php
// Tentukan nama yang akan ditampilkan.
// Jika $namaPengguna ada (dari subfolder), gunakan itu. Jika tidak, gunakan nama default.
$displayName = isset($namaPengguna) ? $namaPengguna : 'Rasyid Kurniawan';
?>
<div class="nama-pengguna"><?= htmlspecialchars($displayName) ?></div>
    </header>

    <div id="reminder-container"></div>

    <div class="form-container">
        <h2><i class="fa-solid fa-pen-to-square"></i> Input & Edit Amalan Harian</h2>
        
        <?php if ($pesan_sukses): ?>
            <div class="pesan pesan-sukses"><?= htmlspecialchars($pesan_sukses) ?></div>
        <?php endif; ?>

        <form id="form-amalan" action="" method="POST">
             <input type="hidden" name="is_ajax" value="save_data">
             <input type="hidden" name="daftar_amalan_structure" value='<?= htmlspecialchars(json_encode($daftar_amalan)) ?>'>
             <input type="hidden" name="rawatib_details_structure" value='<?= htmlspecialchars(json_encode($rawatib_details)) ?>'>

            <div class="form-group">
                <label for="tanggal">Pilih Tanggal:</label>
                <input type="date" id="tanggal" name="tanggal" value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <nav class="quick-nav">
                <a href="#form-sholat-wajib" class="nav-button"><i class="fa-solid fa-mosque"></i> Sholat Wajib</a>
                <a href="#form-sholat-sunnah" class="nav-button"><i class="fa-solid fa-hands-praying"></i> Sholat Sunnah</a>
                <a href="#form-tilawah" class="nav-button"><i class="fa-solid fa-book-quran"></i> Tilawah</a>
                <a href="#form-istighfar" class="nav-button"><i class="fa-solid fa-person-praying"></i> Istighfar</a>
                <a href="#form-puasa" class="nav-button"><i class="fa-solid fa-moon"></i> Puasa Sunnah</a>
                <a href="#form-sedekah" class="nav-button"><i class="fa-solid fa-hand-holding-dollar"></i> Sedekah</a>
                <a href="#form-almatsurat" class="nav-button"><i class="fa-solid fa-shield-halved"></i> Al-Ma'tsurat</a>
            </nav>

            <fieldset id="form-sholat-wajib">
                <legend><i class="fa-solid fa-mosque"></i>Sholat Wajib <?= generate_shortcut_link('SHOLAT WAJIB') ?></legend>
              <div class="prayer-inputs-container">
    <?php foreach($daftar_amalan['SHOLAT WAJIB'] as $key => $label): ?>
    <div class="prayer-input-group prayer-time-<?= $key ?>">
        <label><?= $label ?></label>
        
        <!-- Slider Container (Vertical Layout) -->
        <div class="prayer-slider-wrapper">
            <input type="range" 
                   id="slider-<?= $key ?>" 
                   class="prayer-slider" 
                   data-key="<?= $key ?>"
                   min="0" 
                   max="5" 
                   value="0" 
                   step="1">
            <div class="slider-labels">
                <span data-value="0" class="slider-label active">--</span>
                <span data-value="1" class="slider-label">M</span>
                <span data-value="2" class="slider-label">R-J</span>
                <span data-value="3" class="slider-label">M-S</span>
                <span data-value="4" class="slider-label">R</span>
                <span data-value="5" class="slider-label">Q</span>
            </div>
            <div class="slider-indicator" id="indicator-<?= $key ?>">
                <i class="fa-solid fa-circle-question status-icon"></i> 
                <span>Belum Diisi</span>
            </div>
        </div>
        
        <input type="hidden" name="<?= $key ?>" id="input-<?= $key ?>" value="">
        
        <!-- Qadha Details (hanya muncul saat slider di posisi Q) -->
        <div class="qadha-details-wrapper" id="qadha-details-<?= $key ?>" style="display: none; margin-top: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
            <input type="date" class="qadha-date-input" data-key="<?= $key ?>" aria-label="Tanggal Qadha <?= $label ?>">
            <input type="time" class="qadha-time-input" data-key="<?= $key ?>" aria-label="Waktu Qadha <?= $label ?>">
        </div>

    </div>
    <?php endforeach; ?>
</div>
            </fieldset>

            <fieldset id="form-sholat-sunnah">
                <legend><i class="fa-solid fa-hands-praying"></i>Sholat Sunnah</legend>
                <label>Rawatib Mu'akkad</label>
                <div class="rawatib-grid">
                    <?php foreach($rawatib_details as $key => $label): ?>
                    <div class="checkbox-group">
                        <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>">
                        <label for="<?= $key ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <hr style="margin: 20px 0; border-color: var(--color-border);">
                <div class="form-group-columns">
                    <?php foreach(['dhuha', 'tahajud'] as $key): ?>
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="✓">
                        <label for="<?= $key ?>"><?= $daftar_amalan['SHOLAT SUNNAH'][$key] ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            
            <fieldset id="form-tilawah">
                <legend><i class="fa-solid fa-book-quran"></i>Tilawah Quran</legend>
                <div class="tilawah-grid">
                   <input type="text" id="tilawah_surat" name="tilawah_surat" placeholder="Nama Surat">
                   <input type="text" id="tilawah_ayat_mulai" name="tilawah_ayat_mulai" placeholder="Ayat Ke">
                   <input type="text" id="tilawah_ayat_selesai" name="tilawah_ayat_selesai" placeholder="Sampai Ke">
                </div>
            </fieldset>

            <fieldset id="form-istighfar">
                <legend><i class="fa-solid fa-person-praying"></i>Istighfar <?= generate_shortcut_link('ISTIGHFAR') ?></legend>
                <input type="number" id="istighfar" name="istighfar" min="0" max="200" placeholder="Jumlah" style="display:none;">
                <div class="istighfar-group">
                    <input type="range" id="istighfar_slider" min="0" max="200" step="10" value="0">
                    <div class="istighfar-labels">
                        <span class="istighfar-label active" data-value="0">0</span>
                        <span class="istighfar-label" data-value="20">20</span>
                        <span class="istighfar-label" data-value="50">50</span>
                        <span class="istighfar-label" data-value="100">100</span>
                        <span class="istighfar-label" data-value="150">150</span>
                        <span class="istighfar-label" data-value="200">200</span>
                    </div>
                    <span id="istighfar_value">0</span>
                </div>
            </fieldset>
            
            <fieldset id="form-puasa">
                <legend><i class="fa-solid fa-moon"></i>Puasa Sunnah</legend>
                <div class="form-group-columns">
                    <?php foreach($daftar_amalan['PUASA SUNNAH'] as $key => $label): ?>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="✓">
                            <label for="<?= $key ?>"><?= $label ?></label>
                            <?php if($key === 'ayamul_bidh'): ?>
                                <button type="button" class="info-btn" id="ayamul_bidh_info_btn"><i class="fa-solid fa-circle-info"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            
            <fieldset id="form-sedekah">
                <legend><i class="fa-solid fa-hand-holding-dollar"></i>Sedekah</legend>
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="sedekah" name="sedekah" value="✓" data-details-wrapper="sedekah_details_wrapper">
                        <label for="sedekah">Sedekah</label>
                    </div>
                    <div class="optional-input-wrapper" id="sedekah_details_wrapper" style="display: none;">
                        <input type="text" name="sedekah_detail" id="sedekah_detail" placeholder="Opsional: Tulis jenis sedekah (cth: Uang, Nasi kotak)">
                    </div>
                </div>
            </fieldset>
            
            <fieldset id="form-almatsurat">
                <legend><i class="fa-solid fa-shield-halved"></i>Al-Ma'tsurat <?= generate_shortcut_link('ALMATSURAT') ?></legend>
                <div class="form-group-columns">
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="almatsurat_pagi" name="almatsurat_pagi" value="✓" data-details-wrapper="almatsurat_pagi_details_wrapper">
                            <label for="almatsurat_pagi">Al-Ma'tsurat Pagi</label>
                        </div>
                        <div class="optional-input-wrapper" id="almatsurat_pagi_details_wrapper" style="display: none;">
                            <input type="text" name="almatsurat_pagi_detail" id="almatsurat_pagi_detail" placeholder="Opsional: sampai bagian mana">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="almatsurat_petang" name="almatsurat_petang" value="✓" data-details-wrapper="almatsurat_petang_details_wrapper">
                            <label for="almatsurat_petang">Al-Ma'tsurat Petang</label>
                        </div>
                        <div class="optional-input-wrapper" id="almatsurat_petang_details_wrapper" style="display: none;">
                            <input type="text" name="almatsurat_petang_detail" id="almatsurat_petang_detail" placeholder="Opsional: sampai bagian mana">
                        </div>
                    </div>
                </div>
            </fieldset>

            <div class="original-submit-wrapper">
                 <button type="submit" class="submit-btn">Simpan Data</button>
            </div>
        </form>
    </div>

    <div class="table-container">
        <h2>Laporan Bulan: <?= date('F Y') ?></h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">NO</th>
                        <th rowspan="2" colspan="2" class="th-ibadah">IBADAH</th>
                        <th colspan="<?= $jumlah_hari ?>">TANGGAL</th>
                    </tr>
                    <tr>
                        <?php for ($i = 1; $i <= $jumlah_hari; $i++): ?><th><?= $i ?></th><?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $nomor = 1;
                    foreach ($daftar_amalan as $kategori => $sub_kategori): 
                        $jumlah_sub = count($sub_kategori);
                        $is_first_row_in_category = true;
                    ?>
                    
                    <?php 
                    foreach ($sub_kategori as $key => $label):
                    ?>
                    <tr class="kategori-row">
                        <?php if ($is_first_row_in_category): ?>
                        <td class="kategori-utama td-kategori-header" rowspan="<?= $jumlah_sub ?>"><?= $nomor++ ?></td>
                        <td class="kategori-utama td-kategori-header td-ibadah" rowspan="<?= $jumlah_sub ?>"><?= htmlspecialchars($kategori) ?></td>
                        <?php endif; ?>
                        
                        <td class="td-ibadah"><?= htmlspecialchars($label) ?></td>
                        
                        <?php for ($i = 1; $i <= $jumlah_hari; $i++): 
                            $value = $dataBulanIni[$key][$i] ?? '';
                            if($key == 'istighfar') {
                                if ($value >= 200) $display_val = '✓✓';
                                elseif ($value >= 100) $display_val = '✓';
                                else $display_val = '';
                            } else {
                                $display_val = htmlspecialchars($value);
                                if (strpos($display_val, '✓ (') === 0) {
                                    $display_val = str_replace(['✓ (', ')'], ['✓<br><small>(', ')</small>'], $display_val);
                                }
                                // BARU: Tambahkan kondisi untuk format Qadha yang detail
    elseif (strpos($value, 'Q (') === 0) {
        // Ambil hanya bagian waktu (HH:MM) jika pattern tanggal+waktu ditemukan
        if (preg_match('/Q \((?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2})\)/', $value, $m)) {
            $display_val = 'Q ' . htmlspecialchars($m[1]);
        } else {
            // Fallback: hapus kurung dan tampilkan sisanya
            $detail = trim(str_replace(['Q (', ')'], '', $value));
            $display_val = 'Q ' . htmlspecialchars($detail);
        }
    }
                            }
                        ?>
                            <td class="<?= getCellColorClass($key, $value) ?>"><?= $display_val ?></td>
                        <?php endfor; ?>
                    </tr>
                    <?php 
                        $is_first_row_in_category = false;
                    endforeach; 
                    ?>
                    
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
           <div class="download-actions">
               <a href="download.php" class="download-btn"><i class="fa-solid fa-file-lines"></i> Download Laporan (.txt)</a>
               <button type="button" id="download-pdf-btn" class="download-btn pdf-btn"><i class="fa-solid fa-file-pdf"></i> Download Tabel (.pdf)</button>
        </div>
    </div>
    
    <div id="penjelasan-fitur">
        <div class="feature-card">
            <h3><i class="fa-solid fa-palette"></i> Laporan Berwarna</h3>
            <ul>
                <li style="color:var(--color-good-text)"><strong>Hijau:</strong> Amalan tuntas atau dalam kondisi terbaik (misal: Sholat di Masjid berjamaah).</li>
                <li style="color:var(--color-ok-2-text)"><strong>Biru:</strong> Amalan terlaksana dengan baik (misal: Rawatib sebagian, Istighfar > 100).</li>
                <li style="color:var(--color-ok-1-text)"><strong>Kuning:</strong> Amalan terlaksana namun tidak dalam kondisi ideal (misal: Sholat di rumah sendiri ).</li>
                <li style="color:var(--color-qadha-text)"><strong>Merah:</strong> Menandakan Qadha.</li>
            </ul>
        </div>
        <!-- <div class="feature-card">
            <h3><i class="fa-solid fa-hand-pointer"></i> Input Cepat & Praktis</h3>
            <ul>
                <li><strong>Sholat - Slider Vertikal:</strong> Geser untuk memilih status sholat (Masjid, Rumah, Qadha, dll). Tap 2× pada indikator untuk cycle status berikutnya.</li>
                <li><strong>Istighfar - Double-Tap:</strong> Tap 2× cepat pada angka istighfar untuk berpindah ke nilai berikutnya (0→20→50→100→150→200→0). Lihat badge "👆2×" di pojok kanan atas.</li>
                <li><strong>Klik/Tap Label:</strong> Klik atau tap langsung pada label untuk melompat ke status/nilai tersebut dengan cepat.</li>
            </ul>
        </div> -->
        <a href="https://refleksiformentee.xo.je/" target="_blank" rel="noopener noreferrer" style="text-decoration: none; color: inherit;">
    <div class="feature-card">
        <h3><i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Web Refleksi Mentee</h3>
        <p>Buka dan isi platform refleksi untuk mentee di tab baru untuk evaluasi yang lebih baik.</p>
    </div>
</a>
    </div>

</div>

<div class="floating-save-container" id="floating-save-area">
    <div class="container">
        <p><i class="fa-solid fa-triangle-exclamation"></i> Ada perubahan yang belum disimpan.</p>
        <button type="submit" form="form-amalan" class="submit-btn">Simpan Perubahan</button>
    </div>
</div>

<div class="modal-overlay" id="ayamul_bidh_modal">
    <div class="modal-content">
        <button type="button" class="modal-close-btn">&times;</button>
        <h3 id="modal_title"></h3>
        <p id="modal_hadith"></p>
        <p id="modal_desc"></p>
        <h4 id="modal_dates_title"></h4>
        <ul id="modal_dates_list"></ul>
        <p class="disclaimer" id="modal_disclaimer"></p>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    let semuaDataAmalan = <?= json_encode($semuaDataAmalan); ?>;
    const daftarAmalanStructure = <?= json_encode($daftar_amalan); ?>;
    const form = document.getElementById('form-amalan');
    const tanggalInput = document.getElementById('tanggal');
    const rawatibKeys = <?= json_encode(array_keys($rawatib_details)); ?>;
    const prayerKeys = <?= json_encode(array_keys($daftar_amalan['SHOLAT WAJIB'])); ?>;
    let ayyamulBidhInfo = <?= json_encode($ayyamul_bidh_info); ?>;

    function getSanitizedName(rawName) {
        if (!rawName) return 'tidak-diketahui';
        const safe = rawName.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '');
        return safe || 'tidak-diketahui';
    }

    function getDownloadTimestamp() {
        const now = new Date();
        const pad = (num) => String(num).padStart(2, '0');
        return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
    }

    function downloadCurrentTableAsPdf() {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('Fitur PDF belum siap. Coba muat ulang halaman.');
            return;
        }

        const tableContainer = document.querySelector('.table-container');
        const table = tableContainer ? tableContainer.querySelector('table') : null;
        if (!table) {
            alert('Tabel laporan tidak ditemukan.');
            return;
        }

        const nameEl = document.querySelector('.nama-pengguna');
        const safeName = getSanitizedName(nameEl ? nameEl.textContent.trim() : '');
        const monthStr = tanggalInput && tanggalInput.value ? tanggalInput.value.substring(0, 7) : 'bulan-aktif';
        const timestamp = getDownloadTimestamp();
        const fileName = `laporan-tabel-${safeName}-${monthStr}_${timestamp}.pdf`;

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a3' });
        if (typeof doc.autoTable !== 'function') {
            alert('Plugin PDF belum siap. Coba muat ulang halaman.');
            return;
        }

        const monthTitle = tableContainer.querySelector('h2') ? tableContainer.querySelector('h2').textContent : `Laporan Bulan: ${monthStr}`;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text('Tracker Amalan Yaumiyah', 40, 40);
        doc.setFontSize(11);
        doc.text(monthTitle, 40, 58);

        doc.autoTable({
            html: table,
            startY: 74,
            margin: { left: 24, right: 24, bottom: 24 },
            styles: {
                fontSize: 7,
                cellPadding: 2,
                overflow: 'linebreak',
                valign: 'middle',
                halign: 'center'
            },
            headStyles: {
                fillColor: [75, 96, 124],
                textColor: 255,
                fontStyle: 'bold'
            },
            bodyStyles: {
                textColor: [25, 28, 28]
            },
            theme: 'grid'
        });

        doc.save(fileName);
    }

    const downloadPdfBtn = document.getElementById('download-pdf-btn');
    if (downloadPdfBtn) {
        downloadPdfBtn.addEventListener('click', downloadCurrentTableAsPdf);
    }

    // --- DYNAMIC TIME-BASED BACKGROUND GRADIENT ---
    function updateBackgroundByTime() {
        const now = new Date();
        const hour = now.getHours();
        
        // Hapus semua class time sebelumnya
        document.body.classList.remove('time-dawn', 'time-subuh', 'time-morning', 'time-noon', 'time-asr', 'time-maghrib', 'time-night');
        
        // Tambahkan class sesuai waktu
        if (hour >= 0 && hour < 5) {
            document.body.classList.add('time-dawn'); // Dini Hari
        } else if (hour >= 5 && hour < 6) {
            document.body.classList.add('time-subuh'); // Subuh/Fajar
        } else if (hour >= 6 && hour < 11) {
            document.body.classList.add('time-morning'); // Pagi
        } else if (hour >= 11 && hour < 15) {
            document.body.classList.add('time-noon'); // Siang/Dzuhur
        } else if (hour >= 15 && hour < 18) {
            document.body.classList.add('time-asr'); // Ashar
        } else if (hour >= 18 && hour < 19) {
            document.body.classList.add('time-maghrib'); // Maghrib
        } else {
            document.body.classList.add('time-night'); // Malam
        }
    }
    
    // Jalankan saat halaman dimuat
    updateBackgroundByTime();
    
    // Update setiap 1 menit untuk transisi yang smooth
    setInterval(updateBackgroundByTime, 60000);

    // --- TOAST NOTIFICATION LOGIC ---
    const toast = document.getElementById('toast-notification');
    let toastTimeout;

    function showToast(message, type = 'success') {
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

    // --- FORM SUBMISSION WITH AJAX ---
    form.addEventListener('submit', function(e) {
        e.preventDefault(); // Mencegah reload halaman
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

        const formData = new FormData(form);

        fetch('', { // Mengirim ke halaman ini sendiri
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'success');
                semuaDataAmalan = data.updated_data; // Update data global
                updateFormForDate(tanggalInput.value); // Memuat ulang form dan tabel
                
                // Reset initialFormState setelah berhasil disimpan
                // Sehingga beforeunload tidak akan memblokir lagi
                setTimeout(() => {
                    initialFormState = getCurrentFormState();
                    document.body.classList.remove('show-floating-save');
                }, 100);
            } else {
                showToast(data.message || 'Terjadi kesalahan.', 'error');
            }
        })
        .catch(error => {
            showToast('Gagal terhubung ke server.', 'error');
            console.error('Error:', error);
        })
        .finally(() => {
            if (submitBtn) {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
            if (floatingSubmitBtn) {
                floatingSubmitBtn.innerHTML = floatingOriginalText;
                floatingSubmitBtn.disabled = false;
            }
        });
    });


    // --- CHANGE DETECTION LOGIC ---
    let initialFormState = '';

    function getCurrentFormState() {
        const data = new FormData(form);
        let formString = '';
        // Urutkan key agar konsisten
        const sortedKeys = Array.from(data.keys()).sort();
        sortedKeys.forEach(key => {
            if(key !== 'daftar_amalan_structure' && key !== 'rawatib_details_structure'){
                formString += `${key}=${data.get(key)}&`;
            }
        });
        return formString;
    }

    window.checkForChanges = function() {
        if (getCurrentFormState() !== initialFormState) {
            document.body.classList.add('show-floating-save');
            updateFloatingPosition(); // Update posisi floating berdasarkan submit button
        } else {
            document.body.classList.remove('show-floating-save');
        }
    }
    
    // --- FUNGSI UNTUK MENENTUKAN POSISI FLOATING SAVE ---
    function updateFloatingPosition() {
        const originalSubmitWrapper = document.querySelector('.original-submit-wrapper');
        const floatingContainer = document.getElementById('floating-save-area');
        
        if (!originalSubmitWrapper || !floatingContainer) return;
        
        // Dapatkan posisi submit button relatif terhadap viewport
        const rect = originalSubmitWrapper.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const submitButtonCenter = rect.top + (rect.height / 2);
        
        // Floating muncul di sisi yang SAMA dengan submit button
        // Jika submit button ada di bawah tengah viewport, floating juga di bawah
        // Jika submit button ada di atas tengah viewport, floating juga di atas
        if (submitButtonCenter > viewportHeight / 2) {
            // Submit button di bawah, floating juga di bawah (default)
            floatingContainer.classList.remove('position-top');
            document.body.classList.add('floating-was-bottom');
            document.body.classList.remove('floating-was-top');
        } else {
            // Submit button di atas, floating juga di atas
            floatingContainer.classList.add('position-top');
            document.body.classList.add('floating-was-top');
            document.body.classList.remove('floating-was-bottom');
        }
    }
    
    // Update posisi saat window resize atau scroll
    let updatePositionTimeout;
    function schedulePositionUpdate() {
        clearTimeout(updatePositionTimeout);
        updatePositionTimeout = setTimeout(() => {
            if (document.body.classList.contains('show-floating-save')) {
                updateFloatingPosition();
            }
        }, 100);
    }
    
    window.addEventListener('resize', schedulePositionUpdate);
    window.addEventListener('scroll', schedulePositionUpdate);
    
    // --- PREVENT PAGE REFRESH IF THERE ARE UNSAVED CHANGES ---
    window.addEventListener('beforeunload', function(e) {
        // Cek apakah ada perubahan yang belum disimpan
        if (getCurrentFormState() !== initialFormState) {
            // Pesan peringatan standar browser
            e.preventDefault();
            e.returnValue = ''; // Chrome requires returnValue to be set
            return ''; // Legacy browsers
        }
    });
    
    // --- INTERSECTION OBSERVER FOR SUBMIT BUTTON VISIBILITY ---
    // Deteksi ketika tombol submit original terlihat di viewport
    const originalSubmitWrapper = document.querySelector('.original-submit-wrapper');
    
    if (originalSubmitWrapper) {
        const observerOptions = {
            root: null, // viewport
            threshold: 0.1, // 10% dari element terlihat
            rootMargin: '0px 0px -50px 0px' // margin bawah untuk memicu lebih awal
        };
        
        const submitButtonObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Tombol submit original terlihat - trigger animasi dramatis
                    // Tambah class dengan delay untuk trigger animation
                    requestAnimationFrame(() => {
                        document.body.classList.add('submit-visible');
                    });
                } else {
                    // Tombol submit original tidak terlihat - tampilkan floating button jika ada perubahan
                    document.body.classList.remove('submit-visible');
                }
            });
        }, observerOptions);
        
        submitButtonObserver.observe(originalSubmitWrapper);
    }
    
    // --- OPTIONAL DETAILS INPUT LOGIC ---
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

    // --- AYYAMUL BIDH MODAL LOGIC ---
    const modal = document.getElementById('ayamul_bidh_modal');
    const openModalBtn = document.getElementById('ayamul_bidh_info_btn');
    const closeModalBtn = modal.querySelector('.modal-close-btn');

    openModalBtn.addEventListener('click', () => {
        document.getElementById('modal_title').textContent = ayyamulBidhInfo.title;
        document.getElementById('modal_desc').textContent = ayyamulBidhInfo.description;
        document.getElementById('modal_hadith').textContent = ayyamulBidhInfo.hadith;
        document.getElementById('modal_dates_title').textContent = ayyamulBidhInfo.dates_title;
        document.getElementById('modal_disclaimer').textContent = ayyamulBidhInfo.disclaimer;
        
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
    });
    const closeModal = () => modal.classList.remove('active');
    closeModalBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    
    // --- FUNGSI UNTUK FETCH INFO AYYAMUL BIDH ---
    async function fetchAyyamulBidh(tanggalStr) {
        const formData = new FormData();
        formData.append('is_ajax', 'get_ayyamul_bidh');
        formData.append('tanggal', tanggalStr);

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            ayyamulBidhInfo = data;
        } catch (error) {
            console.error('Gagal mengambil data Ayyamul Bidh:', error);
            // Revert to default/empty state if fetch fails
            ayyamulBidhInfo = { dates: [], title: 'Info Puasa', dates_title: 'Gagal memuat jadwal' };
        }
    }


    // --- Quick Navigation Scroll ---
    document.querySelectorAll('.nav-button').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            document.querySelector(targetId).scrollIntoView({ behavior: 'smooth' });
        });
    });

    // --- Istighfar Slider (Vertical) ---
    const istighfarInput = document.getElementById('istighfar');
    const istighfarSlider = document.getElementById('istighfar_slider');
    const istighfarValueDisplay = document.getElementById('istighfar_value');
    const istighfarLabels = document.querySelectorAll('.istighfar-label');
    
    function updateIstighfarLabel(value) {
        istighfarLabels.forEach(label => {
            const labelValue = parseInt(label.dataset.value);
            label.classList.toggle('active', labelValue === parseInt(value));
        });
    }
    
    istighfarSlider.addEventListener('input', () => {
        const value = istighfarSlider.value;
        istighfarInput.value = value;
        istighfarValueDisplay.textContent = value;
        updateIstighfarLabel(value);
    });
    
    // Label click handler
    istighfarLabels.forEach(label => {
        label.addEventListener('click', () => {
            const value = label.dataset.value;
            istighfarSlider.value = value;
            istighfarInput.value = value;
            istighfarValueDisplay.textContent = value;
            updateIstighfarLabel(value);
            if (typeof window.checkForChanges === 'function') {
                window.checkForChanges();
            }
        });
    });
    
    // Double-click/Double-tap on istighfar value display to cycle
    istighfarValueDisplay.title = 'Klik/Tap 2x untuk menambah nilai';
    let lastIstighfarTap = 0;
    const istighfarDoubleTapDelay = 300; // milliseconds
    const istighfarSteps = [0, 20, 50, 100, 150, 200]; // Nilai-nilai yang bisa dicycle
    
    function cycleIstighfarValue() {
        const currentValue = parseInt(istighfarSlider.value);
        // Cari index nilai saat ini dalam array steps
        let currentIndex = istighfarSteps.findIndex(step => step >= currentValue);
        if (currentIndex === -1) currentIndex = istighfarSteps.length - 1;
        
        // Pindah ke nilai berikutnya, cycle kembali ke 0 setelah 200
        let nextIndex = (currentIndex + 1) % istighfarSteps.length;
        const nextValue = istighfarSteps[nextIndex];
        
        istighfarSlider.value = nextValue;
        istighfarInput.value = nextValue;
        istighfarValueDisplay.textContent = nextValue;
        updateIstighfarLabel(nextValue);
        
        // Visual feedback
        istighfarValueDisplay.style.transform = 'scale(1.15)';
        setTimeout(() => {
            istighfarValueDisplay.style.transform = 'scale(1)';
        }, 200);
        
        if (typeof window.checkForChanges === 'function') {
            window.checkForChanges();
        }
    }
    
    // Desktop: Double-click event
    istighfarValueDisplay.addEventListener('dblclick', function(e) {
        e.preventDefault();
        cycleIstighfarValue();
    });
    
    // Mobile: Touch event untuk double-tap
    istighfarValueDisplay.addEventListener('touchend', function(e) {
        const currentTime = new Date().getTime();
        const tapLength = currentTime - lastIstighfarTap;
        
        if (tapLength < istighfarDoubleTapDelay && tapLength > 0) {
            // Double tap detected
            e.preventDefault();
            cycleIstighfarValue();
            lastIstighfarTap = 0; // Reset
        } else {
            // Single tap
            lastIstighfarTap = currentTime;
        }
    });

    // --- MAIN FORM UPDATE & TABLE REBUILD FUNCTION ---
    function scrollReportToSelectedDate(selectedDay) {
        const tableWrapper = document.querySelector('.table-wrapper');
        const tbody = document.querySelector('.table-container table tbody');
        if (!tableWrapper || !tbody) return;

        const firstDataRow = tbody.querySelector('tr');
        if (!firstDataRow) return;

        // Kolom tanggal dimulai setelah 3 kolom awal: NO, KATEGORI, IBADAH.
        const targetCell = firstDataRow.children[2 + selectedDay];
        if (!targetCell) return;

        const targetLeft = targetCell.offsetLeft - (tableWrapper.clientWidth / 2) + (targetCell.clientWidth / 2);
        const maxScrollLeft = tableWrapper.scrollWidth - tableWrapper.clientWidth;
        const clampedLeft = Math.max(0, Math.min(targetLeft, maxScrollLeft));

        tableWrapper.scrollTo({ left: clampedLeft, behavior: 'smooth' });
    }

    function updateFormForDate(tanggalStr) {
        form.reset(); 
        
        istighfarSlider.value = 0;
        istighfarValueDisplay.textContent = '0';
        updateIstighfarLabel(0);
        prayerKeys.forEach(key => window.setPrayerSliderState(key, ''));
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
                    window.setPrayerSliderState(amalanKey, nilai);
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
                        istighfarSlider.value = val;
                        istighfarValueDisplay.textContent = val;
                        updateIstighfarLabel(val);
                    } else if (element.tagName !== 'BUTTON') {
                            element.value = nilai; 
                        }
                    }
                }
            }
            
            rawatibKeys.forEach(key => {
                const element = form.elements[key];
                if(element) {
                    element.checked = (dataBulanIni[key]?.[hari] === '✓');
                }
            });

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
            tableContainer.querySelector('h2').textContent = `Laporan Bulan: ${monthName}`;
            
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
            let headerHtml = `<tr><th rowspan="2">NO</th><th rowspan="2" colspan="2" class="th-ibadah">IBADAH</th><th colspan="${daysInMonth}">TANGGAL</th></tr><tr>`;
            for (let i = 1; i <= daysInMonth; i++) { headerHtml += `<th>${i}</th>`; }
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
                bodyHtml += `<td class="td-ibadah">${label}</td>`;
                for (let i = 1; i <= daysInMonth; i++) {
                    const value = dataBulanIni[key]?.[i] ?? '';
                    const colorClass = getCellColorClassJS(key, value);
                    let displayVal = value.toString().replace(/</g, "&lt;").replace(/>/g, "&gt;");

                    if (key === 'istighfar') {
                         if (value >= 200) displayVal = '✓✓';
                         else if (value >= 100) displayVal = '✓';
                         else displayVal = '';
                    } else if (displayVal.startsWith('✓ (')) {
                        displayVal = displayVal.replace('✓ (', '✓<br><small>(').replace(')', ')</small>');
                    } else if (displayVal.startsWith('Q (')) {
                        // Extract time only (HH:MM) from pattern like: Q (YYYY-MM-DD HH:MM)
                        const qmatch = displayVal.match(/^Q \((?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2})\)$/);
                        if (qmatch) {
                            displayVal = 'Q ' + qmatch[1];
                        } else {
                            // Fallback: remove parentheses and keep the inner text
                            displayVal = displayVal.replace('Q (', 'Q ').replace(')', '');
                        }
                    }
                    bodyHtml += `<td class="${colorClass}">${displayVal}</td>`;
                }
                bodyHtml += `</tr>`;
                isFirstRow = false;
            }
        }
        tbody.innerHTML = bodyHtml;
        scrollReportToSelectedDate(hari);

        initialFormState = getCurrentFormState();
        document.body.classList.remove('show-floating-save');
    }
    
    // --- JS Version of getCellColorClass ---
    function getCellColorClassJS(key, value) {
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
                if (value >= 100) return 'status-ok-2';
                return 'status-empty';
            case 'tilawah':
                return 'status-ok-2';
            default:
                return 'status-good';
        }
    }


    form.addEventListener('input', checkForChanges);

    tanggalInput.addEventListener('change', async function() {
        await fetchAyyamulBidh(this.value); // Tunggu info baru
        updateFormForDate(this.value); // Baru update form dan tabel
    });
    
    // Reminder & Download Button
    const today = new Date();
    const currentDay = today.getDate();
    const reminderContainer = document.getElementById('reminder-container');
    if (currentDay > 25) {
        const monthStr = "<?= $bulan_sekarang ?>";
        const nameEl = document.querySelector('.nama-pengguna');
        const nameVal = nameEl ? nameEl.textContent.trim() : '';
        const nameParam = nameVal ? `&name=${encodeURIComponent(nameVal)}` : '';
        reminderContainer.innerHTML = `
            <div class="pesan pesan-pengingat">
                <strong>Pengingat Akhir Bulan!</strong> 
                <span>Jangan lupa untuk mengunduh laporan bulan ini sebelum berganti bulan.</span>
                <a href="download.php?month=${monthStr}${nameParam}" class="download-btn"><i class="fa-solid fa-download"></i> Download Laporan</a>
            </div>`;
    }

    // Initial load
    updateFormForDate(tanggalInput.value);
});
</script>

<?php include __DIR__ . '/prayer_slider_handler.php'; ?>
</body>
</html>
