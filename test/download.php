<?php
// FILE: download.php

// --- PENGATURAN DASAR ---
date_default_timezone_set('Asia/Jakarta');
if (!isset($dataFile)) {
    $dataFile = __DIR__ . '/data_amalan.json';
}

// Pastikan struktur ini SAMA PERSIS dengan di index.php
$daftar_amalan = [
    'SHOLAT WAJIB' => [
        'subuh' => 'Subuh', 'dzuhur' => 'Dzuhur', 'ashar' => 'Ashar', 'maghrib' => 'Maghrib', 'isya' => 'Isya'
    ],
    'SHOLAT SUNNAH' => [
        'rawatib' => 'Rawatib', 'dhuha' => 'Dhuha', 'tahajud' => 'Tahajud'
    ],
    'PUASA SUNNAH' => [
        'senin_kamis' => 'Senin/Kamis', 'ayamul_bidh' => 'Ayamul Bidh'
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


function bacaDataAmalan($file) {
    if (!file_exists($file)) return [];
    $dataJson = file_get_contents($file);
    return json_decode($dataJson, true) ?: [];
}

$bulan_diminta = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan_diminta)) {
    die("Format bulan tidak valid.");
}

$semuaDataAmalan = bacaDataAmalan($dataFile);
$dataBulanIni = $semuaDataAmalan[$bulan_diminta] ?? [];
$jumlah_hari = date('t', strtotime($bulan_diminta . '-01'));

// --- Hitung metrik keterlaksanaan ---
// Cari daftar key rawatib detail jika ada dalam data (pattern rawatib_*)
$rawatib_detail_keys = [];
foreach ($semuaDataAmalan as $m => $mdata) {
    foreach ($mdata as $k => $v) {
        if (strpos($k, 'rawatib_') === 0) $rawatib_detail_keys[$k] = true;
    }
}
$rawatib_detail_count = count($rawatib_detail_keys) > 0 ? count($rawatib_detail_keys) : 5; // fallback 5

$total_done = 0;
$total_possible = 0;

// Per-jenis tally (non-rawatib keys from daftar_amalan)
$per_type_done = [];
foreach ($daftar_amalan as $cat => $sub) {
    foreach ($sub as $key => $label) {
        if ($key === 'rawatib') continue; // handle separately
        $per_type_done[$key] = 0;
    }
}

// Count per day
for ($i = 1; $i <= $jumlah_hari; $i++) {
    // Non-rawatib keys
    foreach ($daftar_amalan as $cat => $sub) {
        foreach ($sub as $key => $label) {
            if ($key === 'rawatib') continue;
            $total_possible += 1;
            $value = $dataBulanIni[$key][$i] ?? '';
            $done = 0;
            if (in_array($key, ['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh', 'sedekah', 'almatsurat_pagi', 'almatsurat_petang'])) {
                $done = ($value == '✓') ? 1 : 0;
            } elseif ($key == 'istighfar') {
                $done = (is_numeric($value) && $value > 0) ? 1 : 0;
            } elseif ($key == 'tilawah') {
                $done = ($value !== '') ? 1 : 0;
            } else {
                // sholat wajib
                $done = ($value !== '') ? 1 : 0;
            }
            $per_type_done[$key] += $done;
            $total_done += $done;
        }
    }

    // Rawatib: possible = rawatib_detail_count per day
    $total_possible += $rawatib_detail_count;
    // Prefer aggregate 'rawatib' x/y if present
    $raw_val = $dataBulanIni['rawatib'][$i] ?? '';
    $raw_done = 0;
    if ($raw_val && strpos($raw_val, '/') !== false) {
        list($x, $y) = explode('/', $raw_val);
        $raw_done = (int)$x;
    } else {
        // fallback: count individual rawatib_* keys for this day
        foreach ($rawatib_detail_keys as $rk => $_) {
            if (!empty($dataBulanIni[$rk][$i]) && $dataBulanIni[$rk][$i] === '✓') $raw_done++;
        }
    }
    $total_done += $raw_done;
}

// Persentase keterlaksanaan per jenis (hitung berdasarkan jumlah hari)
$per_type_percent = [];
foreach ($per_type_done as $key => $doneCount) {
    $per_type_percent[$key] = $jumlah_hari > 0 ? round(($doneCount / $jumlah_hari) * 100, 1) : 0;
}

// Rawatib totals: total done and total possible
$total_rawatib_possible = $rawatib_detail_count * $jumlah_hari;
$total_rawatib_done = 0;
for ($i = 1; $i <= $jumlah_hari; $i++) {
    $raw_val = $dataBulanIni['rawatib'][$i] ?? '';
    if ($raw_val && strpos($raw_val, '/') !== false) {
        list($x, $y) = explode('/', $raw_val);
        $total_rawatib_done += (int)$x;
    } else {
        foreach ($rawatib_detail_keys as $rk => $_) {
            if (!empty($dataBulanIni[$rk][$i]) && $dataBulanIni[$rk][$i] === '✓') $total_rawatib_done++;
        }
    }
}

// Overall percentage
$overall_percent = $total_possible > 0 ? round(($total_done / $total_possible) * 100, 1) : 0;

// Nama file teks

// Terima nama pengguna (opsional) — kirim dari front-end sebagai query param `name`
$nama_pengguna = trim($_GET['name'] ?? '');
// Hilangkan karakter baris baru/tab agar tidak merusak format teks
$nama_pengguna = preg_replace("/[\r\n\t]+/", ' ', $nama_pengguna);

// Buat nama file sesuai format yang diminta:
// laporan-amalan-$nama_pengguna-$tahun-$bulan_$tanggaldanjamdidownload.txt
$safe_name = $nama_pengguna !== '' ? strtolower($nama_pengguna) : 'tidak-diketahui';
// Hapus semua spasi dan karakter non-alfanumerik (tidak ada '-' atau '_')
$safe_name = preg_replace('/\s+/', '', $safe_name); // hapus spasi
$safe_name = preg_replace('/[^a-z0-9]/', '', $safe_name); // sisakan hanya a-z dan 0-9
if ($safe_name === '') $safe_name = 'tidak-diketahui';

$year = date('Y', strtotime($bulan_diminta . '-01'));
$month = date('m', strtotime($bulan_diminta . '-01'));
$download_ts = date('YmdHis'); // timestamp saat unduh (tanpa '-' atau '_')
$nama_file = "laporan-amalan-{$safe_name}-{$year}-{$month}_{$download_ts}.txt";

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nama_file . '"');

$output = fopen('php://output', 'w');

// Tambahkan BOM agar Windows/Notepad membaca UTF-8 dengan benar
fputs($output, "\xEF\xBB\xBF");

// Header serupa contoh refleksi
fwrite($output, str_repeat("=", 50) . "\n");
fwrite($output, "      HASIL REKAP AMALAN BULANAN" . "\n");
fwrite($output, str_repeat("=", 50) . "\n\n");

// Terima nama pengguna (opsional) — kirim dari front-end sebagai query param `name`
$nama_pengguna = trim($_GET['name'] ?? '');
// Hilangkan karakter baris baru/tab agar tidak merusak format teks
$nama_pengguna = preg_replace("/[\r\n\t]+/", ' ', $nama_pengguna);

// Metadata
if ($nama_pengguna !== '') {
    fwrite($output, "Nama                : " . $nama_pengguna . "\n");
}
fwrite($output, "Periode             : " . $bulan_diminta . "\n");
fwrite($output, "Tanggal Generate    : " . date('d F Y, H:i') . "\n");
// Waktu unduh (saat file dibuat)
fwrite($output, "Waktu Unduh         : " . date('d F Y, H:i:s') . "\n\n");

// Perjelasan: jelaskan bahwa angka merujuk ke bulan yang diminta
fwrite($output, "Catatan             : Data ini untuk bulan yang diminta (" . $bulan_diminta . "). Jika Anda meminta bulan saat ini, laporan mungkin belum lengkap karena bulan sedang berjalan." . "\n\n");

// Ringkasan singkat
fwrite($output, str_repeat("-", 50) . "\n");
fwrite($output, "Ringkasan Umum" . "\n");
fwrite($output, str_repeat("-", 50) . "\n");

$total_activities = 0;
foreach ($daftar_amalan as $k => $s) $total_activities += count($s);
fwrite($output, "Jumlah hari         : " . $jumlah_hari . "\n");
// Sekarang "Jumlah amalan" direvisi menjadi jumlah yang sudah dikerjakan selama bulan
fwrite($output, "Jumlah amalan       : " . $total_done . " dari " . $total_possible . " (" . $overall_percent . "% )\n");
fwrite($output, "Total rawatib       : " . $total_rawatib_done . " dari " . $total_rawatib_possible . " rakaat tercatat\n\n");

$sectionNo = 1;
foreach ($daftar_amalan as $kategori => $sub_kategori) {
    fwrite($output, str_repeat("-", 50) . "\n");
    fwrite($output, $sectionNo . ". " . $kategori . "\n");
    fwrite($output, str_repeat("-", 50) . "\n");

    foreach ($sub_kategori as $key => $label) {
        // Kumpulkan data per hari
        $entries = [];
        $count_yes = 0;
        $sum_numeric = 0;
        $max_numeric = 0;
        $status_counts = [];
        for ($i = 1; $i <= $jumlah_hari; $i++) {
            $value = $dataBulanIni[$key][$i] ?? '';

            if (in_array($key, ['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh', 'sedekah', 'almatsurat_pagi', 'almatsurat_petang'])) {
                if ($value == '✓') $count_yes++;
            } elseif ($key == 'rawatib') {
                if ($value !== '') $entries[] = $i . ": " . $value;
            } elseif ($key == 'istighfar') {
                if (is_numeric($value) && $value > 0) {
                    $sum_numeric += (int)$value;
                    if ((int)$value > $max_numeric) $max_numeric = (int)$value;
                }
            } elseif ($key == 'tilawah') {
                if ($value !== '') {
                    if (is_numeric($value)) { $sum_numeric += (int)$value; if ((int)$value > $max_numeric) $max_numeric = (int)$value; }
                    else $entries[] = $i . ": " . $value;
                }
            } else {
                // Sholat Wajib (status)
                if ($value !== '') {
                    $status_map = [
                        'M' => 'Masjid (Jamaah)', 'R-J' => 'Rumah (Jamaah)',
                        'M-S' => 'Masjid (Sendiri)', 'R' => 'Rumah (Sendiri)', 'Q' => 'Qadha'
                    ];
                    $display_val = $status_map[$value] ?? $value;
                    if (!isset($status_counts[$display_val])) $status_counts[$display_val] = 0;
                    $status_counts[$display_val]++;
                }
            }
        }

        // Tulis ringkasan untuk label
        fwrite($output, $label . "\n");
        $doneCountForType = $per_type_done[$key] ?? null;
        $percentForType = isset($per_type_percent[$key]) ? $per_type_percent[$key] : null;
        if (in_array($key, ['dhuha', 'tahajud', 'senin_kamis', 'ayamul_bidh', 'sedekah', 'almatsurat_pagi', 'almatsurat_petang'])) {
            $pctText = ($percentForType !== null) ? " (" . $percentForType . "% dari hari)" : '';
            fwrite($output, "Tercatat            : " . $count_yes . " kali" . $pctText . "\n\n");
        } elseif ($key == 'rawatib') {
            if (count($entries) > 0) {
                fwrite($output, "Catatan per hari     : " . implode(', ', $entries) . "\n\n");
            } else {
                fwrite($output, "Catatan per hari     : Tidak ada\n\n");
            }
        } elseif ($key == 'istighfar') {
            if ($sum_numeric > 0) {
                fwrite($output, "Total istighfar      : " . $sum_numeric . " (max per hari: " . $max_numeric . ")\n\n");
            } else {
                fwrite($output, "Total istighfar      : Tidak ada\n\n");
            }
        } elseif ($key == 'tilawah') {
            if ($sum_numeric > 0) {
                fwrite($output, "Total tilawah        : " . $sum_numeric . " (max per hari: " . $max_numeric . ")\n\n");
            } elseif (count($entries) > 0) {
                fwrite($output, "Catatan tilawah      : " . implode(', ', $entries) . "\n\n");
            } else {
                fwrite($output, "Catatan tilawah      : Tidak ada\n\n");
            }
        } else {
            // Sholat wajib: tampilkan counts per status
            if (count($status_counts) > 0) {
                $parts = [];
                foreach ($status_counts as $st => $c) $parts[] = $st . ": " . $c;
                // Tambahkan persen keterlaksanaan untuk jenis ini jika tersedia
                $pctText = ($percentForType !== null) ? " (" . $percentForType . "% dari hari)" : '';
                fwrite($output, "Ringkasan status     : " . implode(', ', $parts) . $pctText . "\n\n");
            } else {
                $pctText = ($percentForType !== null) ? " (" . $percentForType . "% dari hari)" : '';
                fwrite($output, "Ringkasan status     : Tidak ada" . $pctText . "\n\n");
            }
        }
    }

    $sectionNo++;
}

fwrite($output, "\nSelesai.\n");

fclose($output);
exit();
