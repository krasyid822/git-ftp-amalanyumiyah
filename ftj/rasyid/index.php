<?php
// FILE: ftj/rasyid/index.php
//
// Stub "satu orang bisa di beberapa grup": TIDAK ada data_amalan.json di folder
// ini, supaya orang yang sama tidak punya banyak berkas data yang berbeda.
//
// Berbeda dari versi lama yang me-redirect (302) ke ../../test/index.php, di
// sini aplikasi dirender DI TEMPAT (require). Alasannya:
//  - URL tetap /ftj/rasyid/index.php sehingga masih DI DALAM scope manifest PWA
//    (/ftj/rasyid/). Redirect keluar scope membuat app PWA keluar dari mode
//    standalone (dibuka di browser biasa).
//  - Nilai $manifestPath tetap terbawa, jadi PWA memakai manifest_rasyid.json
//    (nama + ikon + start_url yang benar), bukan manifest_test.json.
//
// Beri tahu test/index.php grup tujuan lewat $_GET (semantik sama dengan stub
// redirect lama, tapi tanpa berpindah URL).

// 1. Manifest PWA milik mentee ini.
$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_rasyid.json';

// 2. Nama tampilan.
$folder_name  = basename(__DIR__);
$namaPengguna = ucwords(str_replace(['_', '-'], ' ', $folder_name));

// 3. Grup tujuan untuk kartu target & sumber target_settings.json.
//    (Nilainya sengaja dipaku di sini, bukan dari query, supaya tak bisa diubah
//    pengunjung.)
$_GET['mentee'] = 'rasyid';
$_GET['group']  = 'ftj';

// 4. Catatan: $dataFile TIDAK di-set di sini. test/index.php akan memakai
//    berkas data miliknya (test/data_amalan.json) agar satu orang tetap satu data.

require __DIR__ . '/../../test/index.php';
