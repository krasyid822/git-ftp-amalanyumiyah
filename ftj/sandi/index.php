<?php
// FILE: ahmad/index.php

// 1. Definisikan path ke manifest LOKAL
$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_sandi.json';

// 2. Ambil nama folder saat ini
$folder_name = basename(__DIR__);

// 3. Format nama folder menjadi nama pengguna yang rapi
$namaPengguna = ucwords(str_replace(['_', '-'], ' ', $folder_name));

// 4. Tentukan path ke file data LOKAL
$dataFile = __DIR__ . '/data_amalan.json';

// 5. Muat seluruh aplikasi dari folder 'test'
require '../../test/index.php';