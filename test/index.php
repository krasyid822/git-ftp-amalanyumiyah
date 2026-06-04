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



    // --- C. AJAX UNTUK MENYIMPAN SATU SEL TERTENTU ---
    if ($_POST['is_ajax'] === 'save_single_cell' && isset($_POST['tanggal']) && isset($_POST['key'])) {
        $tanggal = $_POST['tanggal'];
        $bulan = date('Y-m', strtotime($tanggal));
        $hari = (int)date('d', strtotime($tanggal));
        $key = $_POST['key'];
        $value = $_POST['value'] ?? '';

        $dataAmalan = bacaDataAmalan($dataFile);

        // Daftar key detail rawatib
        $rawatib_details_keys = ['rawatib_subuh_q', 'rawatib_dzuhur_q', 'rawatib_dzuhur_b', 'rawatib_maghrib_b', 'rawatib_isya_b'];

        if ($key === 'rawatib') {
            // Jika payload adalah rawatib (summary), maka value berupa JSON dari detail rawatib yang dipilih
            $details = json_decode($value, true);
            $rawatib_done = 0;
            foreach ($rawatib_details_keys as $rkey) {
                if (!empty($details[$rkey])) {
                    $rawatib_done++;
                    $dataAmalan[$bulan][$rkey][$hari] = '✓';
                } else {
                    $dataAmalan[$bulan][$rkey][$hari] = '';
                }
            }
            $dataAmalan[$bulan]['rawatib'][$hari] = ($rawatib_done > 0) ? $rawatib_done . '/' . count($rawatib_details_keys) : '';
        } elseif ($key === 'tilawah') {
            $dataAmalan[$bulan]['tilawah'][$hari] = bi_normalize_tilawah_text($value);
        } else {
            if ($key === 'istighfar') {
                $istighfar_val = (int)$value;
                $dataAmalan[$bulan]['istighfar'][$hari] = $istighfar_val > 0 ? $istighfar_val : '';
            } else {
                $dataAmalan[$bulan][$key][$hari] = $value;
            }
        }

        simpanDataAmalan($dataFile, $dataAmalan);

        $response = [
            'status' => 'success',
            'message' => "Data berhasil diperbarui!",
            'updated_data' => bacaDataAmalan($dataFile)
        ];
    }

    echo json_encode($response);
    exit; // Hentikan eksekusi script setelah mengirim response JSON
}


// --- FUNGSI BARU UNTUK MENGGUNAKAN CLASS PERHITUNGAN ---
function getAyyamulBidhInfoFromClass($date = null) {
    require_once __DIR__ . '/AyamulBidhCalc.php';
    
    // Gunakan tanggal yang diberikan atau tanggal hari ini jika null
    $currentDate = $date ?? new DateTime();
    $year = $currentDate->format('Y');
    $month = $currentDate->format('m');
    $daysInMonth = (int)$currentDate->format('t');
    
    $jadwal_puasa_final = [];
    $raw_dates = [];
    $tasyrik_dates = [];
    
    // Scan seluruh hari di bulan Masehi ini untuk memetakan Ayyamul Bidh & Hari Raya/Tasyrik
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%s-%s-%02d', $year, $month, $d);
        
        // Cek Ayyamul Bidh
        if (bi_is_ayyamul_bidh($dateStr)) {
            $raw_dates[] = $dateStr;
            
            // Format tanggal untuk tampilan modal
            $dObj = new DateTime($dateStr);
            $dayName = $dObj->format('l');
            $monthName = $dObj->format('F');
            
            $daysIndo = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
            $monthsIndo = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
            
            $dayIndo = $daysIndo[$dayName] ?? $dayName;
            $monthIndo = $monthsIndo[$monthName] ?? $monthName;
            
            $jadwal_puasa_final[] = sprintf('%s, %d %s %s', $dayIndo, $d, $monthIndo, $year);
        }
        
        // Cek Hari Raya/Tasyrik
        if (bi_is_tasyrik($dateStr)) {
            $tasyrik_dates[] = $dateStr;
        }
    }
    
    if (empty($jadwal_puasa_final)) {
        $jadwal_puasa_final = ["Tidak ada jadwal puasa Ayyamul Bidh di bulan ini."];
    }

    return [
        'title'       => 'Puasa Ayyamul Bidh (Puasa Hari-hari Putih)',
        'description' => 'Puasa sunnah yang dilaksanakan pada tanggal 13, 14, dan 15 setiap bulan Hijriah. Disebut hari-hari putih karena pada malam-malam tersebut, bulan bersinar terang menyinari bumi.',
        'hadith'      => 'Dari Abu Dzar, Rasulullah shallallahu ‘alaihi wa sallam bersabda padanya, “Jika engkau ingin berpuasa tiga hari setiap bulannya, maka berpuasalah pada tanggal 13, 14, dan 15 (dari bulan Hijriyah).” (HR. Tirmidzi dan An Nasa’i)',
        'dates_title' => 'Perkiraan Jadwal Bulan Ini:',
        'dates'       => $jadwal_puasa_final,
        'raw_dates'   => $raw_dates,
        'tasyrik_dates' => $tasyrik_dates,
        'disclaimer'  => 'Perhitungan Hijriah bersumber dari API <a href="https://al-waqt-9cdb7.web.app/" target="_blank" style="color: var(--md-sys-color-primary); font-weight: bold; text-decoration: underline;">Al-Waqt</a>. Akurasi bisa berbeda satu hari tergantung ketetapan wilayah Anda.'
    ];
}

// --- FUNGSI PEMBANTU UNTUK CEK HARI PUASA SUNNAH ---
function bi_is_senin_kamis($dateStr) {
    $dayOfWeek = date('N', strtotime($dateStr)); // 1 for Monday, 4 for Thursday
    return ($dayOfWeek == 1 || $dayOfWeek == 4);
}

function bi_is_tasyrik($dateStr) {
    require_once __DIR__ . '/AyamulBidhCalc.php';
    $calculator = new AyyamulBidhCalculator();
    $calculator->setAdjustment(0);
    
    $date = new DateTime($dateStr);
    
    // Pre-check lokal secara cepat untuk menyaring tanggal yang tidak mungkin
    $localHijri = $calculator->gregorianToHijriLegacy($date);
    $isDzulhijjahCandidate = ($localHijri['month'] == 12 && $localHijri['day'] >= 8 && $localHijri['day'] <= 15);
    $isShawwalCandidate = ($localHijri['month'] == 10 && ($localHijri['day'] >= 29 || $localHijri['day'] <= 3));
    
    if (!$isDzulhijjahCandidate && !$isShawwalCandidate) {
        return false;
    }
    
    $hijri = $calculator->gregorianToHijri($date);
    
    // Idul Adha (10 Dzulhijjah) & Hari Tasyrik (11, 12, 13 Dzulhijjah)
    if ($hijri['month'] == 12 && ($hijri['day'] == 10 || $hijri['day'] == 11 || $hijri['day'] == 12 || $hijri['day'] == 13)) {
        return true;
    }
    
    // Idul Fitri (1 Shawwal)
    if ($hijri['month'] == 10 && $hijri['day'] == 1) {
        return true;
    }
    
    return false;
}

function bi_is_ayyamul_bidh($dateStr) {
    require_once __DIR__ . '/AyamulBidhCalc.php';
    $calculator = new AyyamulBidhCalculator();
    $calculator->setAdjustment(0);
    
    $date = new DateTime($dateStr);
    
    // Pre-check lokal secara cepat untuk menyaring tanggal yang tidak mungkin
    $localHijri = $calculator->gregorianToHijriLegacy($date);
    if ($localHijri['month'] == 12) {
        return false;
    }
    if ($localHijri['day'] < 11 || $localHijri['day'] > 17) {
        return false;
    }
    
    $hijri = $calculator->gregorianToHijri($date);
    
    // Pastikan bukan bulan Dzulhijjah (12)
    if ($hijri['month'] == 12) {
        return false;
    }
    
    return ($hijri['day'] == 13 || $hijri['day'] == 14 || $hijri['day'] == 15);
}

$ayyamul_bidh_info = getAyyamulBidhInfoFromClass(null);


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
            if ($value > 0) return 'status-ok-1'; // Kuning (Belum capai target)
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
$displayName = isset($namaPengguna) ? $namaPengguna : 'Rasyid Kurniawan';

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
            margin: 10px auto;
            padding: 0px 32px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }
        .legend-shortcut-btn {
            transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
        }
        .legend-shortcut-btn:hover {
            background-color: var(--md-sys-color-primary) !important;
            color: #FFFFFF !important;
            box-shadow: var(--md-sys-elevation-level1);
            transform: translateY(-1px);
        }
        .legend-shortcut-btn:active {
            transform: translateY(0);
        }
        
        /* --- FLOATING SAVE BUTTON (Modern Premium Compact Capsule) --- */
        .floating-save-container {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translate(-50%, 120px);
            background: rgba(18, 28, 28, 0.85); /* Slate transparent */
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 8px 16px;
            box-shadow: 0 10px 30px -10px rgba(0, 106, 106, 0.5), 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            border-radius: 50px; /* Pill/Capsule shape */
            border: 1px solid rgba(0, 106, 106, 0.35);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
            opacity: 0;
            pointer-events: none;
            width: auto;
            max-width: 90%;
        }
        
        .floating-save-container .container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 0;
            margin: 0;
            width: 100%;
        }
        
        .floating-save-container p {
            margin: 0;
            font-weight: 700;
            font-size: 0.875rem;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        @keyframes warningPulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .floating-save-container p i {
            font-size: 1rem;
            color: #FFB300;
            animation: warningPulse 1.5s ease-in-out infinite;
        }
        
        /* Floating submit button style */
        .floating-save-container .submit-btn {
            padding: 6px 14px;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 20px;
            text-transform: none;
            background: linear-gradient(135deg, #00BFA5 0%, #00796B 100%);
            color: white;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 191, 165, 0.3);
            transition: all 0.2s ease;
        }
        
        .floating-save-container .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 191, 165, 0.5);
            background: linear-gradient(135deg, #00E5FF 0%, #0097A7 100%);
        }
        
        body.show-floating-save .floating-save-container {
            transform: translate(-50%, 0);
            opacity: 1;
            pointer-events: auto;
        }
        
        body.show-floating-save .original-submit-wrapper {
            opacity: 0.5; /* Fade out original slightly but keep layout intact */
        }
        
        /* Submit button visibility */
        body.submit-visible .original-submit-wrapper {
            opacity: 1 !important;
            visibility: visible !important;
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
            margin-top: 10px;
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
                flex-direction: row;
                gap: 10px;
                padding: 0;
            }
            
            .floating-save-container p {
                font-size: 0.75rem;
            }
            
            .floating-save-container .submit-btn {
                width: auto;
                padding: 5px 12px;
                font-size: 0.725rem;
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
                padding: 0;
            }
            
            .floating-save-container p {
                font-size: 0.725rem;
            }
            
            .floating-save-container .submit-btn {
                padding: 4px 10px;
                font-size: 0.7rem;
            }
        }

        /* --- EDITABLE TABLE CELLS & POPUP INLINE EDITOR --- */
        .editable-cell {
            cursor: pointer;
            position: relative;
            transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
            user-select: none;
        }
        .editable-cell:hover {
            outline: 2px solid var(--md-sys-color-primary);
            outline-offset: -2px;
            box-shadow: var(--md-sys-elevation-level2);
            transform: scale(1.08);
            z-index: 5;
            border-radius: 4px;
        }
        
        /* Animasi kilatan sukses setelah menyimpan */
        @keyframes cellSaveSuccess {
            0% { box-shadow: 0 0 0 0px rgba(76, 175, 80, 0.7); filter: brightness(1.2); }
            50% { box-shadow: 0 0 0 10px rgba(76, 175, 80, 0); filter: brightness(1.3); }
            100% { box-shadow: 0 0 0 0px rgba(76, 175, 80, 0); filter: brightness(1); }
        }
        .cell-save-success {
            animation: cellSaveSuccess 0.8s cubic-bezier(0.2, 0, 0, 1);
        }

        /* Gaya sel terkunci (pengaman) */
        .status-locked {
            background-color: var(--md-sys-color-surface-container-highest) !important;
            background-image: repeating-linear-gradient(45deg, transparent, transparent 8px, rgba(0, 0, 0, 0.04) 8px, rgba(0, 0, 0, 0.04) 16px) !important;
            color: var(--md-sys-color-outline) !important;
            cursor: pointer !important; /* Diubah menjadi pointer agar intuitif untuk diklik */
            opacity: 0.55;
            transition: all 0.2s ease;
        }
        .status-locked:hover {
            opacity: 0.85;
            filter: brightness(0.95);
        }
        .status-locked::after {
            content: '🔒';
            font-size: 0.7rem;
            vertical-align: middle;
            opacity: 0.4;
            display: inline-block;
            margin-left: 2px;
        }

        /* Kolom hari ini (today highlight) */
        .today-column {
            position: relative;
        }
        /* Tint overlay pada sel data hari ini */
        tbody td.today-column::before {
            content: '';
            position: absolute;
            inset: 0;
            background-color: #E6F2F2;
            opacity: 0.4;
            pointer-events: none;
            z-index: 0;
        }
        thead .today-column {
            background: linear-gradient(180deg, var(--md-sys-color-primary) 0%, var(--color-primary-dark) 100%) !important;
            color: var(--md-sys-color-on-primary) !important;
            font-weight: 700;
        }
        /* Garis batas kiri-kanan kolom hari ini dihilangkan agar tidak ada garis vertikal yang mengganggu */
        tbody td.today-column {
        }
        tbody td.today-column.status-empty {
            background-color: #E6F2F2 !important;
            opacity: 1;
        }

        /* Navigasi Bulan di heading tabel */
        .month-navigator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 4px;
        }
        .month-navigator h2 {
            margin: 0;
            font-size: 1.15rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: var(--md-sys-shape-corner-medium);
            user-select: none;
        }
        .month-navigator h2:hover {
            background-color: var(--md-sys-color-primary-container) !important;
            color: var(--md-sys-color-on-primary-container) !important;
        }
        .month-navigator h2:active {
            transform: scale(0.97);
        }
        .month-nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1.5px solid var(--md-sys-color-outline-variant);
            background: var(--md-sys-color-surface-container);
            color: var(--md-sys-color-primary);
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
            padding: 0;
        }
        .month-nav-btn:hover {
            background: var(--md-sys-color-primary);
            color: var(--md-sys-color-on-primary);
            border-color: var(--md-sys-color-primary);
            box-shadow: var(--md-sys-elevation-level2);
            transform: scale(1.08);
        }
        .month-nav-btn:active {
            transform: scale(0.95);
        }

        /* Mini Confirm Popover untuk Quick Toggle */
        .mini-confirm-popover {
            position: absolute;
            background: var(--md-sys-color-surface-container-lowest);
            border: 1px solid var(--md-sys-color-outline-variant);
            border-radius: var(--md-sys-shape-corner-medium);
            box-shadow: var(--md-sys-elevation-level3);
            padding: 10px 14px;
            z-index: 1001;
            animation: popoverFadeIn 0.15s cubic-bezier(0.2, 0, 0, 1);
            min-width: 160px;
            text-align: center;
        }
        .mini-confirm-popover p {
            margin: 0 0 10px 0;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--md-sys-color-on-surface);
        }
        .mini-confirm-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .mini-confirm-actions button {
            flex: 1;
            padding: 7px 10px;
            border-radius: 6px;
            border: none;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .mini-confirm-yes {
            background: var(--md-sys-color-primary);
            color: var(--md-sys-color-on-primary);
        }
        .mini-confirm-yes:hover {
            background: var(--color-primary-dark);
        }
        .mini-confirm-no {
            background: var(--md-sys-color-surface-container-high);
            color: var(--md-sys-color-on-surface);
        }
        .mini-confirm-no:hover {
            background: var(--md-sys-color-surface-container-highest);
        }

        /* Popover Inline Editor Container */
        .table-popover {
            position: absolute;
            background: #FFFFFF;
            border: 1px solid var(--md-sys-color-outline-variant);
            border-radius: var(--md-sys-shape-corner-medium);
            box-shadow: var(--md-sys-elevation-level3);
            padding: 14px;
            z-index: 1000;
            min-width: 240px;
            max-width: 320px;
            animation: popoverFadeIn 0.2s cubic-bezier(0.2, 0, 0, 1);
            color: var(--md-sys-color-on-surface);
        }
        @keyframes popoverFadeIn {
            from { opacity: 0; transform: scale(0.95) translateY(5px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .popover-header {
            font-weight: 700;
            font-size: 0.875rem;
            margin-bottom: 12px;
            color: var(--md-sys-color-primary);
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
            padding-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .popover-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .popover-close {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--md-sys-color-on-surface-variant);
            padding: 0 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            transition: background 0.2s;
        }
        .popover-close:hover {
            background-color: var(--md-sys-color-surface-variant);
        }
        .popover-body {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        /* Sholat Wajib Grid di Popover */
        .popover-sholat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin-bottom: 4px;
        }
        .popover-option-btn {
            padding: 8px 4px;
            border-radius: 4px;
            border: 1px solid var(--md-sys-color-outline-variant);
            background: #FFF;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        .popover-option-btn:hover {
            background: var(--md-sys-color-surface-container);
            border-color: var(--md-sys-color-outline);
        }
        .popover-option-btn.active {
            background: var(--md-sys-color-primary);
            color: #FFF;
            border-color: var(--md-sys-color-primary);
        }
        
        /* Form elements & buttons di Popover */
        .popover-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--md-sys-color-outline-variant);
            border-radius: 4px;
            font-size: 0.875rem;
            box-sizing: border-box;
        }
        .popover-input:focus {
            outline: none;
            border-color: var(--md-sys-color-primary);
        }
        .popover-save-btn {
            width: 100%;
            padding: 10px;
            background: var(--md-sys-color-primary);
            color: #FFF;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.875rem;
            transition: background 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .popover-save-btn:hover {
            background: var(--color-primary-dark);
        }
        
        /* Grid untuk Qadha di Popover */
        .popover-qadha-fields {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 6px;
            margin-top: 4px;
        }

        /* --- STICKY COLUMNS FOR TABLE --- */
        /* Header Sticky positioning */
        .table-wrapper thead tr:first-child th:first-child {
            position: sticky;
            left: 0;
            z-index: 12;
            background: linear-gradient(180deg, #E8E8E8 0%, #D0D0D0 100%) !important;
            min-width: 40px;
            max-width: 40px;
            width: 40px;
            box-shadow: 1px 0 0 var(--md-sys-color-outline-variant);
        }
        .table-wrapper thead tr:first-child th.th-ibadah {
            position: sticky;
            left: 40px;
            z-index: 12;
            background: linear-gradient(180deg, #E8E8E8 0%, #D0D0D0 100%) !important;
            min-width: 290px;
            max-width: 290px;
            width: 290px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.08);
        }

        /* Body Sticky positioning */
        /* Column 1: NO */
        .table-wrapper td.kategori-utama:not(.td-ibadah) {
            position: sticky;
            left: 0;
            z-index: 10;
            background-color: var(--md-sys-color-surface-container-high) !important;
            min-width: 40px;
            max-width: 40px;
            width: 40px;
            box-shadow: 1px 0 0 var(--md-sys-color-outline-variant);
        }
        /* Column 2: KATEGORI */
        .table-wrapper td.kategori-utama.td-ibadah {
            position: sticky;
            left: 40px;
            z-index: 10;
            background-color: var(--md-sys-color-surface-container-high) !important;
            min-width: 120px;
            max-width: 120px;
            width: 120px;
            box-shadow: 1px 0 0 var(--md-sys-color-outline-variant);
        }
        /* Column 3: IBADAH */
        .table-wrapper td.td-ibadah:not(.kategori-utama) {
            position: sticky;
            left: 160px;
            z-index: 10;
            background-color: #FFFFFF !important;
            min-width: 170px;
            max-width: 170px;
            width: 170px;
            white-space: nowrap;
            box-shadow: 2px 0 5px rgba(0,0,0,0.08); /* Beri shadow halus di sebelah kanan agar terlihat terpisah saat digeser */
        }
        
        /* Hover effect for row sticky cells */
        tr:hover td.kategori-utama {
            background-color: var(--md-sys-color-surface-container-highest) !important;
        }
        tr:hover td.td-ibadah:not(.kategori-utama) {
            background-color: var(--md-sys-color-surface-container-low) !important;
        }

        /* Nonaktifkan sticky kiri, tapi kunci kolom hari ini di kanan pada mobile portrait */
        @media (max-width: 768px) and (orientation: portrait) {
            .table-wrapper thead tr:first-child th:first-child,
            .table-wrapper thead tr:first-child th.th-ibadah,
            .table-wrapper td.kategori-utama:not(.td-ibadah),
            .table-wrapper td.kategori-utama.td-ibadah,
            .table-wrapper td.td-ibadah:not(.kategori-utama) {
                position: static !important;
                left: auto !important;
                box-shadow: none !important;
                z-index: auto !important;
            }

            /* Kunci kolom hari ini di kanan layar saat digeser ke kiri */
            .table-wrapper th.today-column,
            .table-wrapper td.today-column {
                position: sticky !important;
                right: 0 !important;
                z-index: 15 !important;
                box-shadow: -3px 0 6px rgba(0, 0, 0, 0.16) !important;
            }
            
            /* Warna solid untuk header hari ini */
            .table-wrapper th.today-column {
                background: linear-gradient(180deg, var(--md-sys-color-primary) 0%, var(--color-primary-dark) 100%) !important;
                color: var(--md-sys-color-on-primary) !important;
            }

            /* Hindari efek transparan/bleeding pada sel data hari ini saat digeser */
            .table-wrapper td.today-column {
                opacity: 1 !important;
            }
            .table-wrapper td.today-column.status-empty {
                background-color: #E6F2F2 !important; /* solid primary-container equivalent */
            }
            .table-wrapper td.today-column.status-locked {
                background-color: #E4EBEA !important; /* solid surface-container-high/highest equivalent */
            }
        }

        /* Berikan kenyamanan jarak atas karena header dihilangkan */
        .container {
            padding-top: 0px !important;
        }

        /* Style for Hijri Calibration Details */
        .hijri-calibration-details summary::-webkit-details-marker {
            display: none;
        }
        .hijri-calibration-details[open] .details-chevron {
            transform: rotate(180deg);
        }
        .hijri-calibration-details summary:hover {
            color: var(--color-primary-dark);
        }

    </style>
</head>
<body>
    <noscript>
        <style>
            /* Sembunyikan semua elemen halaman utama dan sesuaikan gaya body */
            body > *:not(noscript) { display: none !important; }
            body {
                background-color: #121212 !important;
                color: #FFFFFF !important;
                margin: 0;
                padding: 0;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                font-family: 'Inter', sans-serif;
                text-align: center;
            }
            .noscript-overlay {
                max-width: 500px;
                padding: 32px;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 24px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
                margin: 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 16px;
            }
            .noscript-gif {
                width: 100%;
                max-width: 320px;
                border-radius: 16px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                border: 2px solid #006a6a;
            }
            .noscript-title {
                font-size: 1.5rem;
                font-weight: 700;
                color: #ffb4a9;
                margin: 0;
            }
            .noscript-desc {
                font-size: 0.9rem;
                color: #e0e0e0;
                line-height: 1.6;
                margin: 0;
            }
            .noscript-steps {
                text-align: left;
                background: rgba(0, 0, 0, 0.2);
                padding: 16px 20px 16px 36px;
                border-radius: 12px;
                font-size: 0.85rem;
                color: #d0d0d0;
                width: 100%;
                box-sizing: border-box;
                line-height: 1.5;
                margin: 0;
            }
            .noscript-steps li {
                margin-bottom: 8px;
            }
            .noscript-steps li:last-child {
                margin-bottom: 0;
            }
        </style>
        <div class="noscript-overlay">
            <img class="noscript-gif" src="https://media2.giphy.com/media/v1.Y2lkPTc5MGI3NjExamgxOHhmbnRjN3ptMThhaGlxcmZhdzBvMGFvejBsbzR0YjNrZTNubSZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/98uQnniC09A2W53IXw/giphy.gif" alt="JavaScript engine required">
            <h1 class="noscript-title">JavaScript Tidak Aktif!</h1>
            <p class="noscript-desc">Aplikasi Amalan Yumiyah memerlukan mesin JavaScript aktif agar semua fitur tabel interaktif, popover pencatatan, dan kalkulasi otomatis dapat bekerja dengan normal.</p>
            <p class="noscript-desc"><strong>Cara Mengaktifkan JavaScript:</strong></p>
            <ol class="noscript-steps">
                <li>Buka <strong>Pengaturan (Settings)</strong> di browser Anda.</li>
                <li>Cari menu <strong>Keamanan dan Privasi (Privacy & Security)</strong>.</li>
                <li>Pilih bagian <strong>Pengaturan Situs (Site Settings)</strong>.</li>
                <li>Cari opsi <strong>JavaScript</strong> dan ubah statusnya menjadi <strong>Diizinkan (Allowed)</strong>.</li>
                <li>Muat ulang (refresh) halaman ini setelah diaktifkan.</li>
            </ol>
        </div>
    </noscript>
    <?php include __DIR__ . '/shortcuts.php'; ?>

<div id="toast-notification"></div>

<div class="container">
    <!-- Hidden form for saving data (fully deprecated visible layout elements to maximize screen space) -->
    <form id="form-amalan" style="display: none;" action="" method="POST">
        <input type="hidden" name="is_ajax" value="save_data">
        <input type="hidden" id="tanggal" name="tanggal" value="<?= date('Y-m-d') ?>">
        <input type="hidden" name="daftar_amalan_structure" value='<?= htmlspecialchars(json_encode($daftar_amalan)) ?>'>
        <input type="hidden" name="rawatib_details_structure" value='<?= htmlspecialchars(json_encode($rawatib_details)) ?>'>

        <!-- SHOLAT WAJIB hidden inputs -->
        <?php foreach ($daftar_amalan['SHOLAT WAJIB'] as $key => $label): ?>
            <input type="hidden" name="<?= $key ?>" id="input-<?= $key ?>" value="">
        <?php endforeach; ?>

        <!-- SHOLAT SUNNAH hidden inputs -->
        <?php foreach ($rawatib_details as $key => $label): ?>
            <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="✓">
        <?php endforeach; ?>
        <?php foreach (['dhuha', 'tahajud'] as $key): ?>
            <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="✓">
        <?php endforeach; ?>

        <!-- TILAWAH hidden inputs -->
        <input type="text" id="tilawah_surat" name="tilawah_surat">
        <input type="text" id="tilawah_ayat_mulai" name="tilawah_ayat_mulai">
        <input type="text" id="tilawah_ayat_selesai" name="tilawah_ayat_selesai">

        <!-- ISTIGHFAR hidden input -->
        <input type="number" id="istighfar" name="istighfar" value="0">

        <!-- PUASA SUNNAH hidden inputs -->
        <?php foreach ($daftar_amalan['PUASA SUNNAH'] as $key => $label): ?>
            <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="✓">
        <?php endforeach; ?>

        <!-- SEDEKAH hidden inputs -->
        <input type="checkbox" id="sedekah" name="sedekah" value="✓" data-details-wrapper="sedekah_details_wrapper">
        <input type="text" name="sedekah_detail" id="sedekah_detail">

        <!-- ALMATSURAT hidden inputs -->
        <input type="checkbox" id="almatsurat_pagi" name="almatsurat_pagi" value="✓" data-details-wrapper="almatsurat_pagi_details_wrapper">
        <input type="text" name="almatsurat_pagi_detail" id="almatsurat_pagi_detail">
        <input type="checkbox" id="almatsurat_petang" name="almatsurat_petang" value="✓" data-details-wrapper="almatsurat_petang_details_wrapper">
        <input type="text" name="almatsurat_petang_detail" id="almatsurat_petang_detail">
    </form>

    <div class="table-container">
        <div class="month-navigator" style="position: relative;">
            <button type="button" class="month-nav-btn" id="month-prev-btn" title="Bulan Sebelumnya"><i class="fa-solid fa-chevron-left"></i></button>
            <h2 id="month-title">
                <span>Laporan Bulan: <?= date('F Y') ?></span>
                <i class="fa-regular fa-calendar-days" style="font-size: 1.1rem; color: var(--md-sys-color-primary); opacity: 0.8;"></i>
            </h2>
            <button type="button" class="month-nav-btn" id="month-next-btn" title="Bulan Berikutnya"><i class="fa-solid fa-chevron-right"></i></button>
            <!-- Invisible datepicker for browser-native calendar navigation -->
            <input type="date" id="native-datepicker" style="position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; overflow: hidden; left: 50%; top: 50%;">
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">NO</th>
                        <th rowspan="2" colspan="2" class="th-ibadah">IBADAH</th>
                        <th colspan="<?= $jumlah_hari ?>">TANGGAL</th>
                    </tr>
                    <tr>
                        <?php $hari_ini = (int)date('j'); for ($i = 1; $i <= $jumlah_hari; $i++): ?><th<?= ($i === $hari_ini) ? ' class="today-column"' : '' ?>><?= $i ?></th><?php endfor; ?>
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
                        
                        <td class="td-ibadah"><span style="white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;"><span><?= htmlspecialchars($label) ?></span><?= generate_shortcut_link($kategori, $key) ?></span></td>
                        
                        <?php for ($i = 1; $i <= $jumlah_hari; $i++): 
                            $value = $dataBulanIni[$key][$i] ?? '';
                            
                            // Pengaman sel puasa sunnah
                            $is_locked = false;
                            if ($key === 'senin_kamis' || $key === 'ayamul_bidh') {
                                $dateStr = $bulan_sekarang . '-' . sprintf('%02d', $i);
                                if ($key === 'senin_kamis' && (!bi_is_senin_kamis($dateStr) || bi_is_tasyrik($dateStr))) {
                                    $is_locked = true;
                                } elseif ($key === 'ayamul_bidh' && !bi_is_ayyamul_bidh($dateStr)) {
                                    $is_locked = true;
                                }
                            }
                            
                            if ($is_locked) {
                                $colorClass = 'status-locked';
                                $display_val = '';
                            } else {
                                $colorClass = getCellColorClass($key, $value);
                                if($key == 'istighfar') {
                                    if ($value >= 200) $display_val = '✓✓';
                                    elseif ($value >= 100) $display_val = '✓';
                                    else $display_val = '';
                                } else {
                                    $display_val = htmlspecialchars($value);
                                    if (strpos($display_val, '✓ (') === 0) {
                                        $display_val = str_replace(['✓ (', ')'], ['✓<br><small>(', ')</small>'], $display_val);
                                    }
                                    elseif (strpos($value, 'Q (') === 0) {
                                        if (preg_match('/Q \((?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2})\)/', $value, $m)) {
                                            $display_val = 'Q ' . htmlspecialchars($m[1]);
                                        } else {
                                            $detail = trim(str_replace(['Q (', ')'], '', $value));
                                            $display_val = 'Q ' . htmlspecialchars($detail);
                                        }
                                    }
                                }
                            }
                            
                            $editableClass = $is_locked ? '' : 'editable-cell';
                        ?>
                            <td class="<?= $editableClass ?> <?= $colorClass ?><?= ($i === $hari_ini) ? ' today-column' : '' ?>" data-key="<?= $key ?>" data-day="<?= $i ?>"><?= $display_val ?></td>
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
        
        <!-- Table Legend & Shortcut Bar -->
        <div class="table-legend-bar" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--md-sys-color-outline-variant); flex-wrap: wrap; gap: 12px;">
            <div class="table-legend-items" style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.8rem; font-weight: 600; color: var(--md-sys-color-on-surface-variant);">
                <span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--color-good); display: inline-block; border: 1px solid var(--color-good-text);"></span> Masjid / Terbaik / Target Tercapai</span>
                <span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--color-ok-2); display: inline-block; border: 1px solid var(--color-ok-2-text);"></span> Terlaksana Baik</span>
                <span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--color-ok-1); display: inline-block; border: 1px solid var(--color-ok-1-text);"></span> Rumah / Sendiri / Belum Capai Target</span>
                <span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--color-qadha); display: inline-block; border: 1px solid var(--color-qadha-text);"></span> Qadha (Khusus Sholat)</span>
            </div>
            
            <a href="https://refleksiformentee.xo.je/" target="_blank" rel="noopener noreferrer" class="legend-shortcut-btn" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 0.8rem; font-weight: 700; color: var(--md-sys-color-primary); background-color: var(--md-sys-color-primary-container); padding: 6px 12px; border-radius: 20px; border: 1px solid rgba(0, 106, 106, 0.2);" title="Buka platform refleksi mentee">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Web Refleksi Mentee
            </a>
        </div>

        <div class="download-actions">
            <div class="nama-pengguna" style="margin-top: 0;"><?= htmlspecialchars($displayName) ?></div>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="download.php" class="download-btn" style="margin-top: 0;"><i class="fa-solid fa-file-lines"></i> Download Laporan (.txt)</a>
                <button type="button" id="download-pdf-btn" class="download-btn pdf-btn" style="margin-top: 0;"><i class="fa-solid fa-file-pdf"></i> Download Tabel (.pdf)</button>
            </div>
        </div>
    </div>

</div>

<div class="floating-save-container" id="floating-save-area">
    <div class="container">
        <p><i class="fa-solid fa-triangle-exclamation"></i> Ada perubahan</p>
        <button type="submit" form="form-amalan" class="submit-btn">Simpan</button>
    </div>
</div>

<div class="modal-overlay" id="ayamul_bidh_modal">
    <div class="modal-content" style="max-height: 90vh; overflow-y: auto;">
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
    
    function isSeninKamisJS(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const day = d.getDay(); // 1 for Monday, 4 for Thursday
        return (day === 1 || day === 4);
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
                cellPadding: 3,
                overflow: 'linebreak',
                valign: 'middle',
                font: 'helvetica'
            },
            theme: 'grid',
            didParseCell: function(data) {
                if (data.section === 'head') {
                    data.cell.styles.fillColor = [0, 106, 106]; // #006A6A (Primary Material You Color)
                    data.cell.styles.textColor = [255, 255, 255];
                    data.cell.styles.halign = 'center';
                    data.cell.styles.fontStyle = 'bold';
                } else if (data.section === 'body') {
                    const htmlCell = data.cell.raw;
                    if (htmlCell) {
                        // Cek jenis kolom
                        if (htmlCell.classList.contains('kategori-utama')) {
                            data.cell.styles.fillColor = [228, 235, 234]; // #E4EBEA
                            data.cell.styles.textColor = [25, 28, 28];
                            data.cell.styles.halign = htmlCell.classList.contains('td-ibadah') ? 'left' : 'center';
                            data.cell.styles.fontStyle = 'bold';
                        } else if (htmlCell.classList.contains('td-ibadah')) {
                            data.cell.styles.fillColor = [255, 255, 255]; // #FFFFFF
                            data.cell.styles.textColor = [25, 28, 28];
                            data.cell.styles.halign = 'left';
                            data.cell.styles.fontStyle = 'normal';
                        } else {
                            // Ini adalah sel amalan (kolom tanggal)
                            data.cell.styles.halign = 'center';
                            if (htmlCell.classList.contains('status-good')) {
                                data.cell.styles.fillColor = [168, 245, 184]; // #A8F5B8
                                data.cell.styles.textColor = [20, 108, 46]; // #146C2E
                                data.cell.styles.fontStyle = 'bold';
                            } else if (htmlCell.classList.contains('status-ok-2')) {
                                data.cell.styles.fillColor = [194, 231, 255]; // #C2E7FF
                                data.cell.styles.textColor = [0, 101, 142]; // #00658E
                            } else if (htmlCell.classList.contains('status-ok-1')) {
                                data.cell.styles.fillColor = [255, 223, 158]; // #FFDF9E
                                data.cell.styles.textColor = [122, 89, 0]; // #7A5900
                            } else if (htmlCell.classList.contains('status-qadha')) {
                                data.cell.styles.fillColor = [255, 218, 214]; // #FFDAD6
                                data.cell.styles.textColor = [186, 26, 26]; // #BA1A1A
                            } else if (htmlCell.classList.contains('status-locked')) {
                                data.cell.styles.fillColor = [222, 229, 228]; // #DEE5E4
                                data.cell.styles.textColor = [111, 121, 120]; // #6F7978
                            } else if (htmlCell.classList.contains('status-empty')) {
                                data.cell.styles.fillColor = [234, 241, 240]; // #EAF1F0
                                data.cell.styles.textColor = [63, 73, 72]; // #3F4948
                            }
                        }
                    }
                }
            }
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
                updateFormForDate._skipScroll = true; // Hindari pergeseran tabel setelah menyimpan data
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
            if(key !== 'daftar_amalan_structure' && key !== 'rawatib_details_structure' && key !== 'tanggal' && key !== 'is_ajax'){
                formString += `${key}=${data.get(key)}&`;
            }
        });
        return formString;
    }

    window.checkForChanges = function() {
        if (getCurrentFormState() !== initialFormState) {
            document.body.classList.add('show-floating-save');
            const activeDate = tanggalInput.value;
            const textEl = document.querySelector('#floating-save-area p');
            if (textEl) {
                textEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="color: #FFB300; animation: warningPulse 1.5s ease-in-out infinite;"></i> Ada perubahan pada tanggal ${activeDate}`;
            }
        } else {
            document.body.classList.remove('show-floating-save');
        }
    }
    
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
    
    // --- INTERSECTION OBSERVER REMOVED ---
    
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
    const closeModalBtn = modal.querySelector('.modal-close-btn');

    window.openAyyamulBidhModal = function() {
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
    
    // Label click handler
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
    
    // Double-click/Double-tap on istighfar value display to cycle
    if (istighfarValueDisplay) {
        istighfarValueDisplay.title = 'Klik/Tap 2x untuk menambah nilai';
    }
    let lastIstighfarTap = 0;
    const istighfarDoubleTapDelay = 300; // milliseconds
    const istighfarSteps = [0, 20, 50, 100, 150, 200]; // Nilai-nilai yang bisa dicycle
    
    function cycleIstighfarValue() {
        if (!istighfarSlider || !istighfarValueDisplay) return;
        const currentValue = parseInt(istighfarSlider.value) || 0;
        // Cari index nilai saat ini dalam array steps
        let currentIndex = istighfarSteps.findIndex(step => step >= currentValue);
        if (currentIndex === -1) currentIndex = istighfarSteps.length - 1;
        
        // Pindah ke nilai berikutnya, cycle kembali ke 0 setelah 200
        let nextIndex = (currentIndex + 1) % istighfarSteps.length;
        const nextValue = istighfarSteps[nextIndex];
        
        istighfarSlider.value = nextValue;
        if (istighfarInput) istighfarInput.value = nextValue;
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
    if (istighfarValueDisplay) {
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
    }

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
        
        if (istighfarSlider) istighfarSlider.value = 0;
        if (istighfarValueDisplay) istighfarValueDisplay.textContent = '0';
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
                        if (istighfarSlider) istighfarSlider.value = val;
                        if (istighfarValueDisplay) istighfarValueDisplay.textContent = val;
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
                            const qmatch = displayVal.match(/^Q \((?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2})\)$/);
                            if (qmatch) {
                                displayVal = 'Q ' + qmatch[1];
                            } else {
                                displayVal = displayVal.replace('Q (', 'Q ').replace(')', '');
                            }
                        }
                    }
                    
                    const editableClass = isLocked ? '' : 'editable-cell';
                    const isTodayCell = isCurrentMonth && i === todayDay;
                    bodyHtml += `<td class="${editableClass} ${colorClass}${isTodayCell ? ' today-column' : ''}" data-key="${key}" data-day="${i}">${displayVal}</td>`;
                }
                bodyHtml += `</tr>`;
                isFirstRow = false;
            }
        }
        tbody.innerHTML = bodyHtml;
        // Hanya scroll otomatis jika BUKAN dipanggil dari saveCellData (inline edit)
        if (!updateFormForDate._skipScroll) {
            // Gunakan setTimeout agar browser selesai menghitung layout dan dimensi elemen sebelum menggulir
            setTimeout(() => {
                scrollReportToSelectedDate(hari);
            }, 150);
        }
        updateFormForDate._skipScroll = false;

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
                if (value > 0) return 'status-ok-1'; // Kuning (Belum capai target)
                return 'status-empty';
            case 'tilawah':
                return 'status-ok-2';
            default:
                return 'status-good';
        }
    }


    form.addEventListener('input', checkForChanges);

    let lastDateValue = tanggalInput.value;

    tanggalInput.addEventListener('change', async function() {
        const newDate = this.value;
        const oldDate = lastDateValue;
        if (newDate === oldDate) return;
        
        if (getCurrentFormState() !== initialFormState) {
            // Kembalikan sementara nilai tanggal agar input konsisten selama dialog konfirmasi aktif
            this.value = oldDate;
            
            const confirmed = await new Promise((resolve) => {
                showCustomSwitchConfirmation(oldDate, newDate, resolve);
            });
            if (confirmed) {
                lastDateValue = newDate;
            }
        } else {
            lastDateValue = newDate;
            await fetchAyyamulBidh(newDate);
            updateFormForDate(newDate);
        }
    });
    
    // Reminder & Download Button
    const today = new Date();
    const currentDay = today.getDate();
    const reminderContainer = document.getElementById('reminder-container');
    if (reminderContainer && currentDay > 25) {
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

    // =========================================================================
    // --- FITUR INLINE TABLE EDITING LOGIC ---
    // =========================================================================
    const popover = document.getElementById('table-inline-popover');
    const popoverCloseBtn = document.getElementById('popover-close-btn');
    const popoverTitleText = document.getElementById('popover-title-text');
    const popoverBodyContent = document.getElementById('popover-body-content');

    function closePopover() {
        if (popover) {
            popover.style.display = 'none';
        }
    }

    if (popoverCloseBtn) {
        popoverCloseBtn.addEventListener('click', closePopover);
    }

    // Helper function to get current value from active form
    function getCurrentFormValue(key) {
        if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
            const input = document.getElementById('input-' + key);
            return input ? input.value : '';
        }
        if (['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh'].includes(key)) {
            const element = form.elements[key];
            return element && element.checked ? '✓' : '';
        }
        if (key === 'istighfar') {
            const element = form.elements[key];
            return element ? element.value : '';
        }
        if (key === 'tilawah') {
            const surat = form.elements.tilawah_surat.value.trim();
            const mulai = form.elements.tilawah_ayat_mulai.value.trim();
            const selesai = form.elements.tilawah_ayat_selesai.value.trim();
            let val = surat;
            if (val && mulai) {
                val += ' ' + mulai;
                if (selesai) val += '-' + selesai;
            }
            return val;
        }
        if (['sedekah', 'almatsurat_pagi', 'almatsurat_petang'].includes(key)) {
            const cb = form.elements[key];
            const detailInput = document.getElementById(key + '_detail');
            const detail = detailInput ? detailInput.value.trim() : '';
            return cb && cb.checked ? (detail ? `✓ (${detail})` : '✓') : '';
        }
        if (key === 'rawatib') {
            const rawatibDetailsKeys = ['rawatib_subuh_q', 'rawatib_dzuhur_q', 'rawatib_dzuhur_b', 'rawatib_maghrib_b', 'rawatib_isya_b'];
            let done = 0;
            rawatibDetailsKeys.forEach(rkey => {
                const element = form.elements[rkey];
                if (element && element.checked) done++;
            });
            return done > 0 ? `${done}/5` : '';
        }
        return '';
    }

    // Tampilkan konfirmasi kustom di dalam floating save container
    function showCustomSwitchConfirmation(oldDate, newDate, resolve, labelName = '', categoryName = '') {
        const area = document.getElementById('floating-save-area');
        const container = area.querySelector('.container');
        
        // Simpan markup asli agar bisa dikembalikan nanti
        const originalHTML = container.innerHTML;
        
        // Custom info amalan yang memicu perpindahan tanggal
        const worshipInfo = (categoryName && labelName) ? ` ke pengisian <strong>${categoryName} &gt; ${labelName}</strong>` : '';

        // Buat HTML konfirmasi kustom
        container.innerHTML = `
            <p style="font-size: 0.8rem; font-weight: 700; color: #FFFFFF; display: flex; align-items: center; gap: 8px; margin: 0; white-space: normal; line-height: 1.4; max-width: 60%;">
                <i class="fa-solid fa-triangle-exclamation" style="color: #FFB300; animation: warningPulse 1.5s ease-in-out infinite;"></i>
                Ada perubahan yang belum disimpan pada tanggal ${oldDate}. Beralih tanggal${worshipInfo} akan membatalkan perubahan tersebut. Lanjutkan?
            </p>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="submit-btn continue-btn" style="background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%); color: white; border: none; padding: 6px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 20px; cursor: pointer; box-shadow: 0 2px 8px rgba(255, 152, 0, 0.3);">Lanjutkan</button>
                <button type="button" class="submit-btn cancel-btn" style="background: rgba(255, 255, 255, 0.15); color: white; border: 1px solid rgba(255, 255, 255, 0.3); padding: 6px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 20px; cursor: pointer;">Batal</button>
            </div>
        `;
        
        // Pastikan floating save container terlihat
        document.body.classList.add('show-floating-save');
        
        // Beri efek wiggles/shake halus saat muncul
        area.style.transform = 'translate(-50%, -10px)';
        setTimeout(() => {
            area.style.transform = 'translate(-50%, 0)';
        }, 150);
        
        const continueBtn = container.querySelector('.continue-btn');
        const cancelBtn = container.querySelector('.cancel-btn');
        
        continueBtn.addEventListener('click', async () => {
            container.innerHTML = originalHTML;
            tanggalInput.value = newDate;
            lastDateValue = newDate; // Sinkronkan lastDateValue
            await fetchAyyamulBidh(newDate);
            updateFormForDate(newDate);
            resolve(true);
        });
        
        cancelBtn.addEventListener('click', () => {
            container.innerHTML = originalHTML;
            // Kembalikan ke teks perubahan awal
            window.checkForChanges();
            resolve(false);
        });
    }

    // Function to ensure date in form matches selected cell date
    async function ensureActiveDate(dateStr, labelName = '', categoryName = '') {
        if (tanggalInput.value !== dateStr) {
            if (getCurrentFormState() !== initialFormState) {
                return new Promise((resolve) => {
                    showCustomSwitchConfirmation(tanggalInput.value, dateStr, resolve, labelName, categoryName);
                });
            }
            tanggalInput.value = dateStr;
            lastDateValue = dateStr; // Sinkronkan lastDateValue
            await fetchAyyamulBidh(dateStr);
            updateFormForDate(dateStr);
        }
        return true;
    }

    // Deteksi klik pada editable-cell atau status-locked
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
        
        // Cari nama kategori ibadah induk dari baris tabel
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

        // Cari nama amalan secara presisi (selalu td-ibadah terakhir di baris tersebut untuk menghindari tabrakan header kategori)
        const labelCells = row.querySelectorAll('.td-ibadah');
        const labelName = labelCells[labelCells.length - 1].textContent.trim();

        // Hindari pergeseran tabel saat mengklik sel secara langsung
        updateFormForDate._skipScroll = true;

        // Pastikan form memuat data tanggal sel yang sesuai
        const dateSwitched = await ensureActiveDate(selectedDateStr, labelName, categoryName);
        if (!dateSwitched) {
            updateFormForDate._skipScroll = false;
            return;
        }

        // Tampilkan popover editor untuk semua sel (termasuk dhuha, tahajud, senin_kamis, ayamul_bidh)
        openPopover(cell, key, day, selectedDateStr, labelName);
    });

    function openPopover(originalCell, key, day, dateStr, labelName) {
        // Cari elemen sel yang baru jika DOM tabel telah dibangun ulang
        const cell = document.querySelector(`.table-container table tbody td[data-key="${key}"][data-day="${day}"]`) || originalCell;
        closePopover();
        
        // Cari nama kategori ibadah induk dari baris tabel
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

        // Tampilkan kategori dan nama ibadah di header popover secara premium
        popoverTitleText.innerHTML = `
            <div style="display: flex; flex-direction: column; line-height: 1.2;">
                <span style="font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--md-sys-color-primary); letter-spacing: 0.5px; margin-bottom: 2px;">${categoryName}</span>
                <span style="font-size: 0.9rem; font-weight: 700; color: var(--md-sys-color-on-surface); display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                    <i class="fa-solid fa-pen-to-square" style="font-size: 0.8rem; color: var(--md-sys-color-primary);"></i> ${labelName} - Tgl ${day}
                </span>
            </div>
        `;
        
        const yearMonth = dateStr.substring(0, 7);
        const currentValue = getCurrentFormValue(key);
        
        // Buat konten popover dinamis
        let bodyHtml = '';
        
        if (['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh'].includes(key)) {
            // QUICK TOGGLE POPOVER (Dhuha, Tahajud, Senin/Kamis, Ayyamul Bidh)
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
            
            // Event listener untuk tombol opsi
            popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    popoverBodyContent.querySelectorAll('.popover-toggle-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const val = this.dataset.value;
                    saveCellData(dateStr, key, val, cell);
                    
                    // Beri Toast informatif
                    showToast(`Status ${categoryName} > ${labelName} berhasil diubah!`, 'success');
                });
            });
            
        } else if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
            // SHOLAT WAJIB
            const sholatStates = [
                { val: '', label: '--', class: 'status-empty', icon: 'fa-circle-question' },
                { val: 'M', label: 'Masjid', class: 'status-good', icon: 'fa-mosque' },
                { val: 'R-J', label: 'R. Jam', class: 'status-ok-2', icon: 'fa-house-user' },
                { val: 'M-S', label: 'M. Sen', class: 'status-ok-1', icon: 'fa-person-praying' },
                { val: 'R', label: 'R. Sen', class: 'status-ok-1', icon: 'fa-house' },
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
            
            // Event listener untuk tombol opsi sholat
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
                        // Simpan instan untuk non-Qadha!
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
            // RAWATIB
            const rawatibSubKeys = {
                'rawatib_subuh_q': '2 Rakaat sebelum Subuh ←🌅',
                'rawatib_dzuhur_q': '2 atau 4 Rakaat sebelum Dzuhur ←☀️',
                'rawatib_dzuhur_b': '2 Rakaat setelah Dzuhur →☀️',
                'rawatib_maghrib_b': '2 Rakaat setelah Maghrib →🌇',
                'rawatib_isya_b': '2 Rakaat setelah Isya →☪️'
            };
            
            bodyHtml += `<div style="display: flex; flex-direction: column; gap: 8px;">`;
            for (const rkey in rawatibSubKeys) {
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
            // ISTIGHFAR
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
            
            // Klik opsi cepat langsung set nilai dan simpan instan!
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
            // TILAWAH
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
            // SEDEKAH ATAU ALMATSURAT
            let isChecked = false;
            let detailVal = '';
            const match = typeof currentValue === 'string' && currentValue.match(/^✓ \((.*)\)$/);
            if (match) {
                isChecked = true;
                detailVal = match[1];
            } else {
                isChecked = (currentValue === '✓');
            }
            
            let labelText = 'Lakukan amalan';
            let placeholderText = 'Detail opsional';
            if (key === 'sedekah') {
                labelText = 'Sedekah';
                placeholderText = 'Cth: Uang, makanan';
            } else if (key === 'almatsurat_pagi') {
                labelText = 'Al-Matsurat Pagi';
                placeholderText = 'Cth: Sampai doa almatsurat';
            } else if (key === 'almatsurat_petang') {
                labelText = 'Al-Matsurat Petang';
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
                    
                    showToast(`Status ${categoryName} > ${labelName} berhasil diubah!`, 'success');
                });
            });
            
            detailText.addEventListener('input', triggerDetailsSave);
        }
        
        // Posisikan Popover secara presisi di dekat sel yang diklik
        // Langkah 1: Tempatkan popover secara tidak terlihat di area luas untuk menghitung ukuran aslinya (tanpa tertekan tepi layar)
        popover.style.left = '0px';
        popover.style.top = '0px';
        popover.style.display = 'block';
        popover.style.visibility = 'hidden';
        
        const popoverWidth = popover.offsetWidth;
        const popoverHeight = popover.offsetHeight;
        
        const cellRect = cell.getBoundingClientRect();
        const offsetParent = popover.offsetParent || document.body;
        const parentRect = offsetParent.getBoundingClientRect();
        
        // Langkah 2: Hitung posisi relatif terpusat terhadap sel berdasarkan koordinat viewport
        let popoverViewportLeft = cellRect.left + (cellRect.width / 2) - (popoverWidth / 2);
        let popoverViewportTop = cellRect.bottom + 8; // default di bawah sel
        
        // Langkah 3: Sesuaikan jika melebihi batas kiri/kanan layar (viewport)
        if (popoverViewportLeft < 10) {
            popoverViewportLeft = 10;
        } else if (popoverViewportLeft + popoverWidth > window.innerWidth - 10) {
            popoverViewportLeft = window.innerWidth - popoverWidth - 10;
        }
        
        // Langkah 4: Sesuaikan jika melebihi batas bawah layar (tampilkan di atas sel)
        if (popoverViewportTop + popoverHeight > window.innerHeight - 10) {
            popoverViewportTop = cellRect.top - popoverHeight - 8;
        }
        
        // Langkah 5: Konversikan koordinat viewport kembali ke koordinat offsetParent mutlak
        const popoverLeft = popoverViewportLeft - parentRect.left;
        const popoverTop = popoverViewportTop - parentRect.top;
        
        popover.style.left = `${popoverLeft}px`;
        popover.style.top = `${popoverTop}px`;
        popover.style.visibility = 'visible';
    }

    // Tampilkan perubahan sel tabel secara visual tanpa simpan instan
    function updateTableCellVisually(cell, key, newVal) {
        const isToday = cell.classList.contains('today-column');
        const colorClass = getCellColorClassJS(key, newVal);
        
        cell.className = `editable-cell ${colorClass}${isToday ? ' today-column' : ''}`;
        
        let displayVal = newVal.toString().replace(/</g, "&lt;").replace(/>/g, "&gt;");
        if (key === 'istighfar') {
             if (newVal >= 200) displayVal = '✓✓';
             else if (newVal >= 100) displayVal = '✓';
             else displayVal = '';
        } else if (displayVal.startsWith('✓ (')) {
            displayVal = displayVal.replace('✓ (', '✓<br><small>(').replace(')', ')</small>');
        } else if (displayVal.startsWith('Q (')) {
            const qmatch = displayVal.match(/^Q \((?:\d{4}-\d{2}-\d{2} )?(\d{2}:\d{2})\)$/);
            if (qmatch) {
                displayVal = 'Q ' + qmatch[1];
            } else {
                displayVal = displayVal.replace('Q (', 'Q ').replace(')', '');
            }
        }
        cell.innerHTML = displayVal;
    }

    function saveCellData(dateStr, key, value, originalCell, shouldClose = true) {
        const day = originalCell.dataset.day;
        // Cari elemen sel yang baru jika DOM tabel telah dibangun ulang
        const cell = document.querySelector(`.table-container table tbody td[data-key="${key}"][data-day="${day}"]`) || originalCell;
        const rawatibDetailsKeys = ['rawatib_subuh_q', 'rawatib_dzuhur_q', 'rawatib_dzuhur_b', 'rawatib_maghrib_b', 'rawatib_isya_b'];

        if (['subuh', 'dzuhur', 'ashar', 'maghrib', 'isya'].includes(key)) {
            window.setPrayerSliderState(key, value);
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
            updateIstighfarLabel(val);
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

        updateTableCellVisually(cell, key, value);

        if (shouldClose) {
            closePopover();
        }

        checkForChanges();

        cell.classList.add('cell-save-success');
        setTimeout(() => cell.classList.remove('cell-save-success'), 800);
    }

    // Klik di luar popover untuk menutup popover secara otomatis
    document.addEventListener('click', function(e) {
        if (popover && popover.style.display === 'block') {
            if (!popover.contains(e.target) && !e.target.closest('.editable-cell') && !e.target.closest('.modal-content') && !e.target.closest('.info-btn')) {
                closePopover();
            }
        }
    });

    // --- NAVIGASI BULAN (PREV / NEXT) ---
    const monthPrevBtn = document.getElementById('month-prev-btn');
    const monthNextBtn = document.getElementById('month-next-btn');

    function navigateMonth(direction) {
        const current = new Date(tanggalInput.value + 'T00:00:00');
        current.setMonth(current.getMonth() + direction);
        // Pastikan hari tidak melebihi jumlah hari di bulan baru
        const daysInNewMonth = new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate();
        const newDay = Math.min(current.getDate(), daysInNewMonth);
        current.setDate(newDay);
        
        const yyyy = current.getFullYear();
        const mm = String(current.getMonth() + 1).padStart(2, '0');
        const dd = String(current.getDate()).padStart(2, '0');
        const newDateStr = `${yyyy}-${mm}-${dd}`;
        
        tanggalInput.value = newDateStr;
        // Trigger change event (which handles fetching ayyamul bidh and rebuilding)
        tanggalInput.dispatchEvent(new Event('change'));
    }

    if (monthPrevBtn) {
        monthPrevBtn.addEventListener('click', () => navigateMonth(-1));
    }
    if (monthNextBtn) {
        monthNextBtn.addEventListener('click', () => navigateMonth(1));
    }

    // --- NAVIGASI TANGGAL/BULAN LEWAT KALENDER BAWAAN (DATEPICKER) ---
    const monthTitle = document.getElementById('month-title');
    const nativeDatepicker = document.getElementById('native-datepicker');

    if (monthTitle && nativeDatepicker) {
        monthTitle.addEventListener('click', function() {
            // Set nilai datepicker agar sesuai dengan tanggal aktif saat ini
            nativeDatepicker.value = tanggalInput.value;
            // Panggil picker bawaan browser secara programmatis
            if (typeof nativeDatepicker.showPicker === 'function') {
                nativeDatepicker.showPicker();
            } else {
                nativeDatepicker.click(); // Fallback untuk browser lawas
            }
        });

        nativeDatepicker.addEventListener('change', function() {
            const selectedDate = this.value; // yyyy-mm-dd
            if (selectedDate) {
                tanggalInput.value = selectedDate;
                tanggalInput.dispatchEvent(new Event('change'));
            }
        });
    }

    // Initial load
    updateFormForDate(tanggalInput.value);
});
</script>

<div id="table-inline-popover" class="table-popover" style="display: none;">
    <div class="popover-header">
        <span class="popover-title" id="popover-title-text"><i class="fa-solid fa-pen-to-square"></i> Edit Amalan</span>
        <button type="button" class="popover-close" id="popover-close-btn">&times;</button>
    </div>
    <div class="popover-body" id="popover-body-content">
        <!-- Dynamic content -->
    </div>
</div>

<?php include __DIR__ . '/prayer_slider_handler.php'; ?>
</body>
</html>
