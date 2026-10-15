<?php
// FILE: satu/index.php
// Halaman grup. Dibuat otomatis oleh test/admin.php.

// $manifestPath diisi otomatis oleh test/admin.php saat grup dibuat / manifest diterbitkan.
$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_satu.json';
$folder_name = basename(__DIR__);
$namaPengguna = strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$analysisRoot = __DIR__;

require_once __DIR__ . '/../test/bi.php';
